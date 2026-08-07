#!/bin/bash

set -e

ENV_FILE=".env.local"
ENV_TEMPLATE=".env"
SYMLINK="N"

# Colors for display
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

function debug() {
  echo -e "${BLUE}[INFO]${NC} $1"
}

function warning() {
  echo -e "${YELLOW}[WARNING]${NC} $1"
}

function error() {
  echo -e "${RED}[ERROR]${NC} $1"
}

function success() {
  echo -e "${GREEN}[OK]${NC} $1"
}

function info() {
  echo -e "${CYAN}[ℹ]${NC} $1"
}

function quit_on_error() {
  error "$BASH_COMMAND at line ${BASH_LINENO[0]}"
  exit 1
}

function usage() {
  echo "Usage: $0 [ -p port ] [-s ] [-c] [-w worker_id] [-m]" 1>&2
  echo "  -p port       : Specify RemoteLabz worker port (default: 8080)"
  echo "  -s            : Create symlink instead of copying files"
  echo "  -c            : Skip interactive configuration (use existing .env.local)"
  echo "  -w worker_id  : Worker ID for messenger.yaml configuration (e.g., 1, 2, 3...)"
  echo "  -m            : Multi-server deployment mode (configure SSH between workers)"
}

function exit_abnormal() {
  usage
  exit 1
}

trap 'quit_on_error' ERR

# ====================================================================
# NEW FUNCTION: Check if cgroup v2 is already active
# ====================================================================
function check_cgroup_v2() {
  debug "Checking cgroup v2 configuration..."
  
  # Check if cgroup v2 is mounted
  if mount | grep -q "cgroup2 on /sys/fs/cgroup type cgroup2"; then
    success "cgroup v2 is already active and mounted"
    return 0
  fi
  
  # Check if configured in grub but not yet active (needs reboot)
  if grep -q "systemd.unified_cgroup_hierarchy=1" /proc/cmdline 2>/dev/null; then
    success "cgroup v2 is configured in kernel parameters"
    return 0
  fi
  
  warning "cgroup v2 is not yet active"
  return 1
}

# ====================================================================
# NEW FUNCTION: Create LVM volume group for LXC containers
# ====================================================================
function create_lxc_volume_group() {
  debug "Checking LVM volume group 'lxc-vg'..."
  
  if command -v vgdisplay &>/dev/null && vgdisplay lxc-vg &>/dev/null; then
    success "LVM volume group 'lxc-vg' already exists"
    return 0
  fi
  
  # Check if LVM2 tools are available
  if ! command -v vgcreate &>/dev/null; then
    debug "LVM2 tools not found, installing..."
    apt-get install -y lvm2
  fi
  
  # Find a suitable physical volume (skip /dev/sda1 which is LVM partition)
  local pv_device=""
  
  # Check if there's free disk space not part of an existing LV
  while read -r device; do
    local device_name=$(basename "$device")
    
    # Skip loop devices, dm devices, and partitions that are already PVs
    if [[ "$device_name" == loop* ]] || [[ "$device_name" == dm-* ]] || [[ "$device_name" == sda1 ]] || [[ "$device_name" == sda2 ]]; then
      continue
    fi
    
    # Check if the device is a whole disk (not a partition)
    if ! [[ "$device_name" =~ [0-9]$ ]]; then
      # Check if there's free space on this disk
      local disk_size=$(blockdev --getsize64 "$device" 2>/dev/null)
      if [ -n "$disk_size" ]; then
        pvc=$(pvs 2>/dev/null | grep "$device_name")
        if [ -z "$pvc" ]; then
          pv_device="$device"
          break
        fi
      fi
    fi
  done < <(ls -1 /dev | grep -E "^(sd[a-z]|vd[a-z]|nvme[0-9]+n[0-9]+)$")
  
  if [ -z "$pv_device" ]; then
    warning "No suitable disk device found for LVM volume group"
    warning "LXC containers will use directory rootfs instead of LVM"
    return 1
  fi
  
  if ! pvs | grep -q "$pv_device"; then
    debug "Creating physical volume on ${pv_device}"
    pvcreate -f "$pv_device"
  fi
  
  debug "Creating volume group 'lxc-vg' on ${pv_device}"
  vgcreate lxc-vg "$pv_device"
  success "LVM volume group 'lxc-vg' created successfully"
  
  return 0
}

# ====================================================================
# NEW FUNCTION: Detect deployment topology
# ====================================================================
function detect_deployment_topology() {
  echo ""
  echo "╔════════════════════════════════════════════════════════════╗"
  echo "║         Deployment Topology Configuration                 ║"
  echo "╚════════════════════════════════════════════════════════════╝"
  echo ""
  
  echo "What type of deployment are you configuring?"
  echo "  1) Single-server (Front + Worker on same machine)"
  echo "  2) Multi-server (Separate Front and Worker servers)"
  echo ""
  
  read -p "Enter your choice (1 or 2) [1]: " topology_choice
  topology_choice=${topology_choice:-1}
  
  if [ "$topology_choice" = "2" ]; then
    export DEPLOYMENT_MODE="multi"
    echo ""
    read -p "How many workers in total in your infrastructure? [1]: " total_workers
    export TOTAL_WORKERS=${total_workers:-1}
    
    read -p "What is the ID of THIS worker? (1, 2, 3...) [1]: " this_worker_id
    export THIS_WORKER_ID=${this_worker_id:-1}
    
    success "Multi-server deployment: Worker ${THIS_WORKER_ID}/${TOTAL_WORKERS}"
  else
    export DEPLOYMENT_MODE="single"
    export TOTAL_WORKERS=1
    export THIS_WORKER_ID=1
    success "Single-server deployment configured"
  fi
  
  echo ""
}

# ====================================================================
# NEW FUNCTION: Interactive configuration of .env.local
# ====================================================================
function configure_env_local() {
  local env_file="$1"
  
  echo ""
  echo "=========================================="
  echo "  Local Environment Configuration"
  echo "=========================================="
  echo ""
  
  if [ -f "${env_file}" ]; then
    warning "The file ${env_file} already exists."
    read -p "Do you want to reconfigure it? (y/n) [n]: " reconfigure
    reconfigure=${reconfigure:-n}
    if [[ ! $reconfigure =~ ^[yY]$ ]]; then
      debug "Keeping existing file."
      return 0
    fi
    # Backup old file
    cp "${env_file}" "${env_file}.backup.$(date +%Y%m%d_%H%M%S)"
    success "Backup created: ${env_file}.backup.$(date +%Y%m%d_%H%M%S)"
  fi
  
  # Copy template
  if [ ! -f "${ENV_TEMPLATE}" ]; then
    error "Template file ${ENV_TEMPLATE} does not exist!"
    exit 1
  fi
  
  cp "${ENV_TEMPLATE}" "${env_file}"
  success "File ${env_file} created from ${ENV_TEMPLATE}"
  
  echo ""
  echo "Configuring network and system parameters..."
  echo ""
  
  # 1. Administration network interface
  echo "─────────────────────────────────────────"
  echo "1. Administration Network Interface"
  echo "─────────────────────────────────────────"
  echo "This interface is used for worker management (SSH, Web access, etc.)"
  echo ""
  
  # Display available interfaces
  echo "Available network interfaces:"
  ip -brief address show | grep -v "^lo" | awk '{print "  - " $1 ": " $3}'
  echo ""
  
  local current_adm=$(grep "^ADM_INTERFACE=" "${env_file}" | cut -d'"' -f2)
  read -p "Administration interface [${current_adm}]: " adm_interface
  adm_interface=${adm_interface:-$current_adm}
  
  if [[ $adm_interface == "ensX" ]]; then
    error "You must specify a valid network interface!"
    exit 1
  fi
  
  sed -i "s/^ADM_INTERFACE=.*/ADM_INTERFACE=\"${adm_interface}\"/" "${env_file}"
  success "Administration interface: ${adm_interface}"
  
  # 2. Data network interface
  echo ""
  echo "─────────────────────────────────────────"
  echo "2. Data Network Interface"
  echo "─────────────────────────────────────────"
  echo "This interface is used for VM data network."
  echo "If you only have one network card, use the same interface."
  echo ""
  
  read -p "Data interface (or Enter to use ${adm_interface}): " data_interface
  data_interface=${data_interface:-$adm_interface}
  
  if [[ $data_interface == "$adm_interface" ]]; then
    sed -i "s/^DATA_INTERFACE=.*/DATA_INTERFACE=\$ADM_INTERFACE/" "${env_file}"
    sed -i "s/^#DATA_INTERFACE=\"ensY\"/# Single interface used/" "${env_file}"
  else
    sed -i "s/^DATA_INTERFACE=.*/DATA_INTERFACE=\"${data_interface}\"/" "${env_file}"
  fi
  success "Data interface: ${data_interface}"
  
  # 3. Worker IP
  echo ""
  echo "─────────────────────────────────────────"
  echo "3. Worker IP Address"
  echo "─────────────────────────────────────────"
  echo "This IP must match the configuration in config/packages/messenger.yaml"
  echo ""
  
  # Try to detect current IP
  local detected_ip=$(ip -4 addr show ${adm_interface} 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n1)
  if [ -n "$detected_ip" ]; then
    echo "Detected IP on ${adm_interface}: ${detected_ip}"
  fi
  
  local current_worker_ip=$(grep "^WORKER_IP=" "${env_file}" | cut -d'"' -f2)
  read -p "Worker IP [${detected_ip:-$current_worker_ip}]: " worker_ip
  worker_ip=${worker_ip:-${detected_ip:-$current_worker_ip}}
  
  sed -i "s/^WORKER_IP=.*/WORKER_IP=\"${worker_ip}\"/" "${env_file}"
  success "Worker IP: ${worker_ip}"
  export WORKER_IP="${worker_ip}"
  
  # 4. Internet access interface
  echo ""
  echo "─────────────────────────────────────────"
  echo "4. Internet Access Interface"
  echo "─────────────────────────────────────────"
  echo "Interface used for VM internet access (often = DATA_INTERFACE)"
  echo ""
  
  read -p "Internet access interface (or Enter for ${data_interface}): " internet_interface
  internet_interface=${internet_interface:-$data_interface}
  
  if [[ $internet_interface == "$data_interface" ]]; then
    sed -i "s/^INTERNET_INTERFACE_ACCESS=.*/INTERNET_INTERFACE_ACCESS=\$DATA_INTERFACE/" "${env_file}"
  else
    sed -i "s/^INTERNET_INTERFACE_ACCESS=.*/INTERNET_INTERFACE_ACCESS=\"${internet_interface}\"/" "${env_file}"
  fi
  success "Internet access interface: ${internet_interface}"
  
  # 5. Front server IP
  echo ""
  echo "─────────────────────────────────────────"
  echo "5. Front Server IP Address"
  echo "─────────────────────────────────────────"
  echo "IP of the RemoteLabz web server (for downloading images and VPN access)"
  echo ""
  
  local current_front=$(grep "^FRONT_SERVER_IP=" "${env_file}" | cut -d'=' -f2)
  read -p "Front server IP [${current_front}]: " front_ip
  front_ip=${front_ip:-$current_front}
  
  sed -i "s/^FRONT_SERVER_IP=.*/FRONT_SERVER_IP=${front_ip}/" "${env_file}"
  success "Front server IP: ${front_ip}"
  export FRONT_SERVER_IP="${front_ip}"
  
  # 6. Data network configuration
  echo ""
  echo "─────────────────────────────────────────"
  echo "6. Data Network Configuration"
  echo "─────────────────────────────────────────"
  echo "Network used for VM communications"
  echo "If you have only one network interface, this data network will not use"
  echo ""
  
  local current_data_net=$(grep "^DATA_NETWORK=" "${env_file}" | cut -d'=' -f2)
  read -p "Data network [${current_data_net}]: " data_network
  data_network=${data_network:-$current_data_net}
  sed -i "s|^DATA_NETWORK=.*|DATA_NETWORK=${data_network}|" "${env_file}"
  
  local current_data_ip=$(grep "^DATA_INT_IP_ADDRESS=" "${env_file}" | cut -d'=' -f2)
  read -p "Internal data IP [${current_data_ip}]: " data_int_ip
  data_int_ip=${data_int_ip:-$current_data_ip}
  sed -i "s|^DATA_INT_IP_ADDRESS=.*|DATA_INT_IP_ADDRESS=${data_int_ip}|" "${env_file}"
  
  local current_data_gw=$(grep "^DATA_INT_GW=" "${env_file}" | cut -d'=' -f2)
  read -p "Data network gateway [${current_data_gw}]: " data_gw
  data_gw=${data_gw:-$current_data_gw}
  sed -i "s|^DATA_INT_GW=.*|DATA_INT_GW=${data_gw}|" "${env_file}"
  
  success "Data network configuration completed"
  
  # 7. VPN configuration
  echo ""
  echo "─────────────────────────────────────────"
  echo "7. VPN Configuration"
  echo "─────────────────────────────────────────"
  echo "If you deploy a worker on the same server than your front, you have to answer yes to this question"
  
  read -p "Is the VPN hosted on this worker? (y/n) [y]: " vpn_local
  vpn_local=${vpn_local:-y}
  
  if [[ $vpn_local =~ ^[yY]$ ]]; then
    sed -i "s/^VPN_CONCENTRATOR_IP=.*/VPN_CONCENTRATOR_IP=\$FRONT_SERVER_IP/" "${env_file}"
    sed -i "s/^VPN_CONCENTRATOR_INTERFACE=.*/VPN_CONCENTRATOR_INTERFACE=\"localhost\"/" "${env_file}"
    success "VPN configured locally (localhost)"
  else
    read -p "VPN concentrator IP: " vpn_ip
    read -p "VPN concentrator interface [${data_interface}]: " vpn_interface
    vpn_interface=${vpn_interface:-$data_interface}
    
    sed -i "s/^VPN_CONCENTRATOR_IP=.*/VPN_CONCENTRATOR_IP=\"${vpn_ip}\"/" "${env_file}"
    sed -i "s/^VPN_CONCENTRATOR_INTERFACE=.*/VPN_CONCENTRATOR_INTERFACE=\"${vpn_interface}\"/" "${env_file}"
    success "VPN configured: ${vpn_ip} via ${vpn_interface}"
  fi
  
  # 8. SSH passwords
  echo ""
  echo "─────────────────────────────────────────"
  echo "8. SSH Configuration"
  echo "─────────────────────────────────────────"
  warning "IMPORTANT: Change default passwords for security!"
  echo ""
  
  read -p "Password for remotelabz-worker user [leave default]: " worker_pass
  if [ -n "$worker_pass" ]; then
    sed -i "s/^SSH_USER_WORKER_PASSWD=.*/SSH_USER_WORKER_PASSWD=\"${worker_pass}\"/" "${env_file}"
    success "Worker password changed"
  fi
  
  read -p "Password for remotelabz (front) user [leave default]: " front_pass
  if [ -n "$front_pass" ]; then
    sed -i "s/^SSH_USER_FRONT_PASSWD=.*/SSH_USER_FRONT_PASSWD=\"${front_pass}\"/" "${env_file}"
    success "Front password changed"
  fi
  
  # 9. Secure WebSocket
  echo ""
  echo "─────────────────────────────────────────"
  echo "9. Secure WebSocket (WSS)"
  echo "─────────────────────────────────────────"
  
  read -p "Enable WSS (Secure WebSocket)? (y/n) [n]: " use_wss
  use_wss=${use_wss:-n}
  
  if [[ $use_wss =~ ^[yY]$ ]]; then
    sed -i "s/^REMOTELABZ_PROXY_USE_WSS=.*/REMOTELABZ_PROXY_USE_WSS=1/" "${env_file}"
    success "WSS enabled - Don't forget to place your certificates in config/certs/"
  else
    sed -i "s/^REMOTELABZ_PROXY_USE_WSS=.*/REMOTELABZ_PROXY_USE_WSS=0/" "${env_file}"
    success "WSS disabled"
  fi
  
  # 10. Contact email
  echo ""
  echo "─────────────────────────────────────────"
  echo "10. Email Configuration"
  echo "─────────────────────────────────────────"
  
  local current_mail=$(grep "^CONTACT_MAIL=" "${env_file}" | cut -d'"' -f2)
  read -p "Contact email [${current_mail}]: " contact_mail
  contact_mail=${contact_mail:-$current_mail}
  sed -i "s/^CONTACT_MAIL=.*/CONTACT_MAIL=\"${contact_mail}\"/" "${env_file}"
  success "Email configured: ${contact_mail}"
  
  echo ""
  success "══════════════════════════════════════════════════════════"
  success "  Configuration completed! File created: ${env_file}"
  success "══════════════════════════════════════════════════════════"
  echo ""
  echo "You can manually edit this file if needed:"
  echo "  nano ${env_file}"
  echo ""
  
  read -p "Do you want to display the configuration summary? (y/n) [y]: " show_summary
  show_summary=${show_summary:-y}
  
  if [[ $show_summary =~ ^[yY]$ ]]; then
    echo ""
    echo "Configuration summary:"
    echo "────────────────────────────────"
    grep -E "^(ADM_INTERFACE|DATA_INTERFACE|WORKER_IP|FRONT_SERVER_IP|DATA_NETWORK|VPN_CONCENTRATOR_IP|CONTACT_MAIL)=" "${env_file}" | sed 's/^/  /'
    echo ""
  fi
}

# ====================================================================
# NEW FUNCTION: Configure cgroup v2 (from update guide 2.5)
# ====================================================================
function configure_cgroup_v2() {
  debug "Configuring cgroup v2"
  
  if grep -q "systemd.unified_cgroup_hierarchy=1" /etc/default/grub; then
    debug "cgroup v2 already configured in GRUB"
    return 0
  fi
  
  # Backup grub file
  cp /etc/default/grub /etc/default/grub.backup.$(date +%Y%m%d_%H%M%S)
  
  # Modify GRUB_CMDLINE_LINUX_DEFAULT line
  if grep -q "^GRUB_CMDLINE_LINUX_DEFAULT=" /etc/default/grub; then
    sed -i 's/^GRUB_CMDLINE_LINUX_DEFAULT="\(.*\)"/GRUB_CMDLINE_LINUX_DEFAULT="\1 systemd.unified_cgroup_hierarchy=1"/' /etc/default/grub
  else
    echo 'GRUB_CMDLINE_LINUX_DEFAULT="systemd.unified_cgroup_hierarchy=1"' >> /etc/default/grub
  fi
  
  update-grub
  success "cgroup v2 configured in GRUB - A reboot will be required"
}

# ====================================================================
# NEW FUNCTION: Configure messenger.yaml for multi-worker
# ====================================================================
function configure_messenger_yaml() {
  local worker_id="$1"
  local worker_ip="$2"
  local messenger_file="${SCRIPTPATH}/config/packages/messenger.yaml"
  
  if [ -z "$worker_id" ]; then
    debug "No worker ID specified, messenger.yaml configuration skipped"
    return 0
  fi
  
  debug "Configuring messenger.yaml for worker ${worker_id}"
  
  if [ ! -f "$messenger_file" ]; then
    warning "messenger.yaml file not found: ${messenger_file}"
    return 0
  fi
  
  # Check if configuration already exists
  if grep -q "messages_worker${worker_id}:" "$messenger_file"; then
    debug "Configuration for worker${worker_id} already present"
    return 0
  fi
  
  # Add configuration for this worker
  cat >> "$messenger_file" << EOF

                queues:
                    messages_worker${worker_id}:
                        binding_keys: [${worker_ip}]
EOF
  
  success "messenger.yaml configuration updated for worker ${worker_id}"
}

# ====================================================================
# NEW FUNCTION: Check and install pamtester in container
# ====================================================================
function setup_container_pamtester() {
  local container_name="$1"
  local distro="$2"  # debian, ubuntu, or alpine
  local rootfs="/var/lib/lxc/${container_name}/rootfs"
  
  if [ ! -d "$rootfs" ]; then
    warning "Container ${container_name} rootfs not found"
    return 1
  fi
  
  sudo iptables -A FORWARD -i lxcbr0 -j ACCEPT
  sudo iptables -A FORWARD -o lxcbr0 -m state --state RELATED,ESTABLISHED -j ACCEPT

  debug "Checking pamtester in ${container_name}..."
  
  # Check if pamtester is already installed
  case "$distro" in
    debian|ubuntu)
      if chroot "$rootfs" dpkg -l pamtester &>/dev/null | grep -q "^ii"; then
        success "✓ pamtester already installed in ${container_name}"
        return 0
      fi
      
      debug "Installing pamtester in ${container_name} (Debian/Ubuntu)..."
      # Start container if not running
      if ! lxc-info -n "$container_name" | grep -q "RUNNING"; then
        lxc-start -n "$container_name" -d
        sleep 3
      fi
      
      # Install pamtester
      lxc-attach -n "$container_name" -- bash -c "apt-get update && apt-get install -y pamtester" 2>/dev/null
      
      if [ $? -eq 0 ]; then
        success "✓ pamtester installed in ${container_name}"
      else
        warning "⚠ Failed to install pamtester in ${container_name} - manual installation needed"
      fi

      # Stop container if not stopped
      if ! lxc-info -n "$container_name" | grep -q "STOPPED"; then
        lxc-stop -n "$container_name"
        sleep 3
      fi

      ;;
      
    alpine)
      if chroot "$rootfs" apk info pamtester &>/dev/null | grep -q "pamtester"; then
        success "✓ pamtester already installed in ${container_name}"
        return 0
      fi
      
      debug "Installing pamtester in ${container_name} (Alpine)..."
      # Start container if not running
      if ! lxc-info -n "$container_name" | grep -q "RUNNING"; then
        lxc-start -n "$container_name" -d
        sleep 3
      fi
      
      # Install pamtester
      lxc-attach -n "$container_name" -- sh -c "apk update && apk add pamtester" 2>/dev/null
      
      if [ $? -eq 0 ]; then
        success "✓ pamtester installed in ${container_name}"
      else
        warning "⚠ Failed to install pamtester in ${container_name} - manual installation needed"
      fi

      # Stop container if not stopped
      if ! lxc-info -n "$container_name" | grep -q "STOPPED"; then
        lxc-stop -n "$container_name"
        sleep 3
      fi

      ;;
  esac
}

# ====================================================================
# NEW FUNCTION: Configure SSH keys between workers (automated)
# ====================================================================
function configure_ssh_between_workers() {
  echo ""
  echo "╔════════════════════════════════════════════════════════════╗"
  echo "║       SSH Configuration Between Workers                    ║"
  echo "╚════════════════════════════════════════════════════════════╝"
  echo ""
  
  if [ "$DEPLOYMENT_MODE" = "single" ]; then
    info "Single-server deployment: SSH between workers not needed"
    return 0
  fi
  
  info "Multi-server deployment detected: Worker ${THIS_WORKER_ID}/${TOTAL_WORKERS}"
  echo ""
  
  if [ ! -f /home/remotelabz-worker/.ssh/myremotelabzkey ]; then
    error "SSH key not found: /home/remotelabz-worker/.ssh/myremotelabzkey"
    error "Please ensure the installation completed successfully"
    return 1
  fi
  
  echo "We need to configure SSH access from this worker to:"
  echo "  - Front server (${FRONT_SERVER_IP})"
  
  if [ "$TOTAL_WORKERS" -gt 1 ]; then
    echo "  - Other worker servers"
  fi
  echo ""
  
  read -p "Do you want to configure SSH access now? (y/n) [y]: " configure_ssh
  configure_ssh=${configure_ssh:-y}
  
  if [[ ! $configure_ssh =~ ^[yY]$ ]]; then
    warning "SSH configuration skipped - You'll need to do this manually later"
    return 0
  fi
  
  # Configure SSH to front server
  echo ""
  echo "── Configuring SSH access to Front server ──"
  echo "IP: ${FRONT_SERVER_IP}"
  echo ""
  
  info "Attempting to copy SSH key to front server..."
  info "You will be prompted for the 'remotelabz' user password on the front server"
  echo ""
  
  if sudo -u remotelabz-worker ssh-copy-id -i /home/remotelabz-worker/.ssh/myremotelabzkey.pub remotelabz@${FRONT_SERVER_IP}; then
    success "SSH key copied to front server"
    
    # Test connection
    info "Testing SSH connection to front server..."
    if sudo -u remotelabz-worker ssh -i /home/remotelabz-worker/.ssh/myremotelabzkey -o BatchMode=yes -o ConnectTimeout=5 remotelabz@${FRONT_SERVER_IP} "echo 'SSH connection successful'" 2>/dev/null; then
      success "✓ SSH connection to front server is working!"
    else
      warning "⚠ SSH connection test failed - please verify manually"
    fi
  else
    warning "Failed to copy SSH key to front server"
    warning "You'll need to do this manually later"
  fi
  
  # Configure SSH to other workers
  if [ "$TOTAL_WORKERS" -gt 1 ]; then
    echo ""
    echo "── Configuring SSH access to other workers ──"
    echo ""
    
    for ((i=1; i<=$TOTAL_WORKERS; i++)); do
      if [ $i -eq $THIS_WORKER_ID ]; then
        continue  # Skip self
      fi
      
      echo ""
      read -p "Enter IP address for Worker ${i}: " other_worker_ip
      
      if [ -z "$other_worker_ip" ]; then
        warning "No IP provided for Worker ${i}, skipping..."
        continue
      fi
      
      info "Attempting to copy SSH key to Worker ${i} (${other_worker_ip})..."
      info "You will be prompted for the 'remotelabz-worker' password"
      echo ""
      
      if sudo -u remotelabz-worker ssh-copy-id -i /home/remotelabz-worker/.ssh/myremotelabzkey.pub remotelabz-worker@${other_worker_ip}; then
        success "SSH key copied to Worker ${i}"
        
        # Test connection
        info "Testing SSH connection to Worker ${i}..."
        if sudo -u remotelabz-worker ssh -i /home/remotelabz-worker/.ssh/myremotelabzkey -o BatchMode=yes -o ConnectTimeout=5 remotelabz-worker@${other_worker_ip} "echo 'SSH connection successful'" 2>/dev/null; then
          success "✓ SSH connection to Worker ${i} is working!"
        else
          warning "⚠ SSH connection test to Worker ${i} failed"
        fi
      else
        warning "Failed to copy SSH key to Worker ${i}"
      fi
    done
  fi
  
  echo ""
  success "SSH configuration completed!"
  echo ""
}

# ====================================================================
# NEW FUNCTION: Display smart post-installation summary
# ====================================================================
function display_post_installation_actions() {
  local needs_reboot=false
  
  echo ""
  echo "╔════════════════════════════════════════════════════════════╗"
  echo "║       Post-Installation Status & Required Actions         ║"
  echo "╚════════════════════════════════════════════════════════════╝"
  echo ""
  
  # Check cgroup v2 status
  if ! check_cgroup_v2; then
    needs_reboot=true
    warning "⚠️  REBOOT REQUIRED"
    echo "   cgroup v2 has been configured but requires a system reboot to take effect"
    echo "   Command: sudo reboot"
    echo ""
  else
    success "✓ cgroup v2 is active and working"
    echo ""
  fi
  
  # SSH Configuration status
  echo "📡 SSH Configuration:"
  echo "────────────────────────────────────────"
  
  if [ "$DEPLOYMENT_MODE" = "single" ]; then
    success "✓ Single-server deployment - No inter-worker SSH needed"
  else
    info "Multi-server deployment (Worker ${THIS_WORKER_ID}/${TOTAL_WORKERS})"
    
    # Check SSH to front
    if sudo -u remotelabz-worker ssh -i /home/remotelabz-worker/.ssh/myremotelabzkey -o BatchMode=yes -o ConnectTimeout=3 remotelabz@${FRONT_SERVER_IP} "exit" 2>/dev/null; then
      success "✓ SSH to front server (${FRONT_SERVER_IP}) is configured"
    else
      warning "⚠ SSH to front server needs configuration"
      echo "   Run: sudo -u remotelabz-worker ssh-copy-id -i /home/remotelabz-worker/.ssh/myremotelabzkey.pub remotelabz@${FRONT_SERVER_IP}"
    fi
  fi
  echo ""
  
  # Messenger.yaml status
  echo "📨 Messenger Configuration:"
  echo "────────────────────────────────────────"
  if [ -n "$THIS_WORKER_ID" ] && [ "$THIS_WORKER_ID" != "" ]; then
    if grep -q "messages_worker${THIS_WORKER_ID}:" "${REMOTELABZ_WORKER_PATH}/config/packages/messenger.yaml" 2>/dev/null; then
      success "✓ messenger.yaml configured for worker ${THIS_WORKER_ID}"
    else
      warning "⚠ messenger.yaml needs manual configuration"
      echo "   Edit: ${REMOTELABZ_WORKER_PATH}/config/packages/messenger.yaml"
    fi
  else
    info "ℹ No worker ID specified - manual messenger.yaml configuration may be needed"
  fi
  echo ""
  
  # Container configuration
  echo "🐧 Container Configuration (pamtester):"
  echo "────────────────────────────────────────"
  
  local containers_ok=0
  local containers_total=0
  
  for container in "Migration" "Debian" "Ubuntu24LTS" "AlpineEdge"; do
    containers_total=$((containers_total + 1))
    
    if lxc-ls -1 | grep -q "^${container}$"; then
      local rootfs="/var/lib/lxc/${container}/rootfs"
      
      # Check pamtester installation based on distro
      case "$container" in
        Migration|Debian)
          if chroot "$rootfs" dpkg -l pamtester 2>/dev/null | grep -q "^ii"; then
            success "✓ ${container}: pamtester installed"
            containers_ok=$((containers_ok + 1))
          else
            warning "⚠ ${container}: pamtester NOT installed"
          fi
          ;;
        Ubuntu24LTS)
          if chroot "$rootfs" dpkg -l pamtester 2>/dev/null | grep -q "^ii"; then
            success "✓ ${container}: pamtester installed"
            containers_ok=$((containers_ok + 1))
          else
            warning "⚠ ${container}: pamtester NOT installed"
          fi
          ;;
        AlpineEdge)
          if chroot "$rootfs" apk info pamtester 2>/dev/null | grep -q "pamtester"; then
            success "✓ ${container}: pamtester installed"
            containers_ok=$((containers_ok + 1))
          else
            warning "⚠ ${container}: pamtester NOT installed"
          fi
          ;;
      esac
    else
      warning "⚠ ${container}: container not found"
    fi
  done
  
  if [ $containers_ok -eq $containers_total ]; then
    success "All containers have pamtester installed ($containers_ok/$containers_total)"
  else
    warning "Some containers need pamtester installation ($containers_ok/$containers_total)"
    echo "   Run the script again or install manually in containers"
  fi
  echo ""
  
  # DHCP configuration
  echo "🌐 DHCP Service:"
  echo "────────────────────────────────────────"
  warning "⚠ Action required: Configure DHCP service"
  echo "   Documentation: https://docs.remotelabz.com/administrators/getting-started/ubuntu-standalone/#add-a-dhcp-service-for-your-laboratory"
  echo ""
  
  # Important files
  echo "📁 Important Files:"
  echo "────────────────────────────────────────"
  echo "   Configuration: ${REMOTELABZ_WORKER_PATH}/.env.local"
  echo "   SSH private key: /home/remotelabz-worker/.ssh/myremotelabzkey"
  echo "   SSH public key: /home/remotelabz-worker/.ssh/myremotelabzkey.pub"
  echo ""
  
  # Final action
  if [ "$needs_reboot" = true ]; then
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║  ⚠️  IMPORTANT: System reboot is required!                ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    read -p "Do you want to reboot now? (y/n) [n]: " do_reboot
    if [[ $do_reboot =~ ^[yY]$ ]]; then
      info "Rebooting system in 5 seconds... (Press Ctrl+C to cancel)"
      sleep 5
      reboot
    else
      warning "Please remember to reboot before using RemoteLabz!"
    fi
  fi
}

# ====================================================================
# MAIN SCRIPT START
# ====================================================================

SKIP_CONFIG=false
WORKER_ID=""
MULTI_SERVER=false

while getopts "p:w:scmh" opt; do
  case ${opt} in
    p)
      export REMOTELABZ_WORKER_PORT="$OPTARG"
      ;;
    w)
      WORKER_ID="$OPTARG"
      ;;
    s)
      export SYMLINK="Y"
      ;;
    c)
      SKIP_CONFIG=true
      ;;
    m)
      MULTI_SERVER=true
      ;;
    h)
      usage
      exit 0
      ;;
    :)
      echo "Option -$OPTARG requires an argument." >&2
      exit_abnormal
      ;;
    *)
      exit_abnormal
      ;;
  esac
done

debug "Starting remoteLabz-worker installation"

# Check for ubuntu >24.04
if [ ! $(which lsb_release) ] || [ $(lsb_release -is) != "Ubuntu" ] || [ $(lsb_release -rs) != "24.04" ]; then
  error "Your platform is unsupported. Please use Ubuntu Server LTS 24.04."
  exit 1
fi

# Check for root
if [ "$(whoami)" != "root" ]; then
    error "Installation aborted, root is required! Please reload the script as root to continue..."
    exit 1
fi

# Detect deployment topology BEFORE configuration
if [ "$SKIP_CONFIG" = false ] && [ "$MULTI_SERVER" = false ]; then
  detect_deployment_topology
  if [ -n "$THIS_WORKER_ID" ]; then
    WORKER_ID="$THIS_WORKER_ID"
  fi
fi

# Interactive .env.local configuration BEFORE installation
if [ "$SKIP_CONFIG" = false ]; then
  configure_env_local "${ENV_FILE}"
  echo ""
  read -p "Press Enter to continue installation..."
fi

# Verify that .env.local file exists now
if [ ! -f ./${ENV_FILE} ]; then
     error "Environment file .env.local not found in ${ENV_FILE}. Please check this file exists and try again."
     exit 1
fi

# Load environment variables
source ./${ENV_FILE}

# Check minimum configuration
if [ "${ADM_INTERFACE}" = "ensX" ]; then
    error "You have to configure your .env.local file before to start the install process"
    error "Please run: $0 (without -c option) to configure interactively"
    exit 1
fi

# Environment variables
if [ -z "$REMOTELABZ_WORKER_PATH" ]; then
    export REMOTELABZ_WORKER_PATH="/opt/remotelabz-worker"
fi
if [ -z "$REMOTELABZ_WORKER_PORT" ]; then
    export REMOTELABZ_WORKER_PORT=8081
fi

export SCRIPTPATH="$( cd "$(dirname "$0")/.." ; pwd -P )"
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export PIP_ROOT_USER_ACTION=ignore

SCRIPT=$(readlink -f "$0")
SCRIPT_DIR=$(dirname "$SCRIPT")

# Configure cgroup v2
configure_cgroup_v2

debug "Running apt-get to grab required packages"
apt-get update
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y apache2 php8.4 php8.4-ssh2 zip unzip qemu-system-x86 qemu-system-arm qemu-kvm openvswitch-switch git pipx python3 python3-pip python3-setuptools python3-wheel python3-numpy python3-openvswitch php8.4-xml php8.4-curl php8.4-amqp logrotate lxc screen build-essential cmake libjson-c-dev libwebsockets-dev curl exim4 sshpass expect pamtester
a2dismod php8.1 php8.2 php8.3 || true

phpenmod -v 8.4 dom
update-alternatives --set php /usr/bin/php8.4
update-alternatives --set phar /usr/bin/phar8.4
update-alternatives --set phar.phar /usr/bin/phar.phar8.4
a2enmod php8.4
systemctl restart apache2
success "Packages installed ✔️"

# Create user remotelabz-worker and remotelabz-worker group
if [ ! $(getent passwd remotelabz-worker) ]; then
  debug "Creating remotelabz-worker user"
  useradd -N -m remotelabz-worker
  usermod --password $(echo remotelabz-worker_pass | openssl passwd -1 -stdin) remotelabz-worker
fi
if [ ! $(getent group remotelabz-worker) ]; then
  debug "Creating remotelabz-worker group"
  groupadd remotelabz-worker
fi

usermod -aG remotelabz-worker www-data
usermod -aG remotelabz-worker remotelabz-worker

chgrp -R remotelabz-worker "${SCRIPTPATH}"
chmod -R g+rwx "${SCRIPTPATH}"

# Create SSH keys
if [ -d "/home/remotelabz-worker" ]; then
    read -p "The /home/remotelabz-worker directory already exists. Do you want to delete it? (y/n) " response
    if [[ $response == [yY] ]]; then
        rm -rf /home/remotelabz-worker
        mkdir /home/remotelabz-worker
        mkdir /home/remotelabz-worker/.ssh
        chown remotelabz-worker:remotelabz-worker /home/remotelabz-worker/.ssh
        chmod 700 /home/remotelabz-worker/.ssh
        
        debug "Generating SSH key: myremotelabzkey"
        sudo -u remotelabz-worker ssh-keygen -m PEM -t rsa -b 4096 -f /home/remotelabz-worker/.ssh/myremotelabzkey -N ""
        chmod 600 /home/remotelabz-worker/.ssh/myremotelabzkey
        
        cat /home/remotelabz-worker/.ssh/myremotelabzkey.pub | sudo tee -a /home/remotelabz-worker/.ssh/authorized_keys
        chmod 600 /home/remotelabz-worker/.ssh/authorized_keys
        
        chown remotelabz-worker:remotelabz-worker /home/remotelabz-worker/.ssh -R

        success "SSH keys created: myremotelabzkey"
        
        sed -i 's|SSH_USER_PRIVATEKEY_FILE=.*|SSH_USER_PRIVATEKEY_FILE="/home/remotelabz-worker/.ssh/myremotelabzkey"|' "${ENV_FILE}"
        sed -i 's|SSH_USER_PUBLICKEY_FILE=.*|SSH_USER_PUBLICKEY_FILE="/home/remotelabz-worker/.ssh/myremotelabzkey.pub"|' "${ENV_FILE}"
    else
        warning "SSH keys cannot be created, please delete the remotelabz-worker directory."
        read -p "Press Enter to continue ..."
    fi
fi

debug "IP configuration of the data network for the VMs and forward between interfaces"
ip addr add "${DATA_INT_IP_ADDRESS}" dev "${DATA_INTERFACE}" || true
sed -i 's/[#| ]*net.ipv4.ip_forward[ ]*=[ |0|1]*/net.ipv4.ip_forward = 1/g' /etc/sysctl.conf
success "IP configuration ✔️"

debug "Adding sudo permissions for remotelabz-worker user"
cp config/sudo/remotelabz-worker /etc/sudoers.d/
chmod 440 /etc/sudoers.d/remotelabz-worker
success "Sudo permissions ✔️"

# Composer
debug "Installing Composer"
if ! [ $(command -v composer) ]; then
    cp composer.phar /usr/local/bin/composer
    success "Composer installed ✔️"
else
  debug "Composer is already installed! Skipping."
fi

debug "Downloading bundles"

mkdir -p "$SCRIPTPATH/lib"

if [ ! -d "$SCRIPTPATH/lib/network-bundle" ]; then
  git clone https://github.com/remotelabz/network-bundle.git "$SCRIPTPATH/lib/network-bundle"
  git -C "$SCRIPTPATH/lib/network-bundle" fetch --tags
  git -C "$SCRIPTPATH/lib/network-bundle" checkout 1.0.4
fi

if [ ! -d "$SCRIPTPATH/lib/remotelabz-message-bundle" ]; then
  git clone https://github.com/remotelabz/remotelabz-message-bundle.git "$SCRIPTPATH/lib/remotelabz-message-bundle"
  git -C "$SCRIPTPATH/lib/remotelabz-message-bundle" fetch --tags
  git -C "$SCRIPTPATH/lib/remotelabz-message-bundle" checkout 1.0.6
fi

debug "Downloading Composer packages"
(cd "${SCRIPTPATH}" && composer install --prefer-dist)
chown -R remotelabz-worker:remotelabz-worker "${SCRIPTPATH}"/vendor
chmod -R 777 "${SCRIPTPATH}"/vendor
success "Composer packages ✔️"

# Folders
debug "Creating images folder if it does not exists already..."
if [ ${SYMLINK} = "Y" ]
then
  ln -fs "${SCRIPTPATH}" "${REMOTELABZ_WORKER_PATH}"
else
  cp -Rf "${SCRIPTPATH}" "${REMOTELABZ_WORKER_PATH}"
fi

mkdir -p "${REMOTELABZ_WORKER_PATH}/images"
chmod g+rwx "${REMOTELABZ_WORKER_PATH}/images"
mkdir -p "${REMOTELABZ_WORKER_PATH}/iso"
chmod g+rwx "${REMOTELABZ_WORKER_PATH}/iso"
mkdir -p "${REMOTELABZ_WORKER_PATH}/instances"
chmod g+rwx "${REMOTELABZ_WORKER_PATH}/instances"
mkdir -p "${REMOTELABZ_WORKER_PATH}/var/cache/resources"
chmod g+rwx "${REMOTELABZ_WORKER_PATH}/var/cache/resources"
sudo chown -R remotelabz-worker:www-data "${REMOTELABZ_WORKER_PATH}/var/"

# Websockify
debug "Installing WebSockify"
if ! [ $(command -v websockify) ]; then
    debug "Installing WebSockify..."
    apt install python3-setuptools
    git clone https://github.com/novnc/websockify.git "${REMOTELABZ_WORKER_PATH}/websockify"
    (cd "${REMOTELABZ_WORKER_PATH}/websockify" && python3 setup.py install)
    rm -rf "${REMOTELABZ_WORKER_PATH}/websockify"
    success "WebSockify installed ✔️"
else
  debug "WebSockify is already installed! Skipping."
fi

# Grant OVS permissions to remotelabz group
chmod g+rwx /var/run/openvswitch/db.sock
chgrp remotelabz-worker /var/run/openvswitch/db.sock

# Configure apache
debug "Configuring Apache with port ${REMOTELABZ_WORKER_PORT}"
if grep -Fxq "Listen ${REMOTELABZ_WORKER_PORT}" /etc/apache2/ports.conf; then
  debug "Port ${REMOTELABZ_WORKER_PORT} is already configured in apache2."
else
  echo "Listen ${REMOTELABZ_WORKER_PORT}" >> /etc/apache2/ports.conf
fi
cp -f "${SCRIPTPATH}"/config/apache/100-remotelabz-worker.conf /etc/apache2/sites-available/100-remotelabz-worker.conf
sed -i "s/Listen 8080/Listen ${REMOTELABZ_WORKER_PORT}/g" /etc/apache2/sites-available/100-remotelabz-worker.conf
sed -i 's,/var/www/html/remotelabz-worker,'"${SCRIPTPATH}"',' /etc/apache2/sites-available/100-remotelabz-worker.conf
ln -fs /etc/apache2/sites-available/100-remotelabz-worker.conf /etc/apache2/sites-enabled/100-remotelabz-worker.conf
apache2ctl restart || true
success "Apache configured ✔️"

# Configure logrotate
debug "Configuring logrotate"
cp -f "${SCRIPTPATH}"/config/logrotate/remotelabz-worker /etc/logrotate.d
success "Logrotate configured ✔️"

debug "Setup remotelabz service"
ln -fs "${SCRIPTPATH}"/bin/systemd/remotelabz-worker.service /etc/systemd/system/remotelabz-worker.service
ln -fs "${SCRIPTPATH}"/bin/systemd/remotelabz-cache.service /etc/systemd/system/remotelabz-cache.service
ln -fs "${SCRIPTPATH}"/bin/systemd/remotelabz-cache.timer /etc/systemd/system/remotelabz-cache.timer
systemctl daemon-reload
systemctl enable remotelabz-worker.service
systemctl enable remotelabz-cache.timer
systemctl start remotelabz-cache.timer
systemctl start remotelabz-worker
systemctl daemon-reload || true
success "Services configured ✔️"

debug "Backup .env.local and copy to final location"
if [ -f "${REMOTELABZ_WORKER_PATH}"/.env.local ]; then
        cp "${REMOTELABZ_WORKER_PATH}"/.env.local "${REMOTELABZ_WORKER_PATH}"/.env.local.bak
fi
cp "./${ENV_FILE}" "${REMOTELABZ_WORKER_PATH}/.env.local"
success "Configuration copied to ${REMOTELABZ_WORKER_PATH} ✔️"

debug "Create certs directory"
REP="${REMOTELABZ_WORKER_PATH}/config/certs/"
if [ ! -d $REP ]; then
        mkdir "${REMOTELABZ_WORKER_PATH}"/config/certs/
fi;
chown :remotelabz-worker "${REMOTELABZ_WORKER_PATH}"/config/certs/
chmod g+w "${REMOTELABZ_WORKER_PATH}"/config/certs/
success "Certificates directory created ✔️"

debug "Installation of ttyd from its github project"
cd ~
if [ ! -d ttyd-1.7.3 ]; then
        wget https://github.com/tsl0922/ttyd/archive/refs/tags/1.7.3.zip
  unzip 1.7.3.zip
        cd ttyd-1.7.3 && mkdir build && cd build
        cmake ..
        make && sudo make install
fi;
success "ttyd installed ✔️"

# Container creation and configuration
debug "Checking and configuring LXC containers..."

# Migration container
LXC=`lxc-ls -1 "Migration" 2>/dev/null`;
if [ "${LXC}" == "" ] ; then
  debug "Creating Migration container..."
  DOWNLOAD_KEYSERVER="keyserver.ubuntu.com" lxc-create -t download -n Migration -- -d debian -r trixie -a amd64
  echo "nameserver 1.1.1.3" > "/var/lib/lxc/Migration/rootfs/etc/resolv.conf"
  success "Migration container created ✔️"
else
  debug "Migration container already exists"
fi
setup_container_pamtester "Migration" "debian"

# Debian container
LXC=`lxc-ls -1 "Debian" 2>/dev/null`;
if [ "${LXC}" == "" ] ; then
  debug "Creating Debian container..."
  DOWNLOAD_KEYSERVER="keyserver.ubuntu.com" lxc-create -t download -n Debian -- -d debian -r trixie -a amd64
  echo "No default login, please use Sandbox to configure a new OS from this" >> "/var/lib/lxc/Debian/rootfs/etc/issue"
  echo "nameserver 1.1.1.3" > "/var/lib/lxc/Debian/rootfs/etc/resolv.conf"
  success "Debian container created ✔️"
else
  debug "Debian container already exists"
fi
setup_container_pamtester "Debian" "debian"

# Ubuntu24LTS container
LXC=`lxc-ls -1 "Ubuntu24LTS" 2>/dev/null`;
if [ "${LXC}" == "" ] ; then
  debug "Creating Ubuntu24LTS container..."
  DOWNLOAD_KEYSERVER="keyserver.ubuntu.com" lxc-create -t download -n Ubuntu24LTS -- -d ubuntu -r noble -a amd64
  echo "No default login, please use Sandbox to configure a new OS from this" >> "/var/lib/lxc/Ubuntu24LTS/rootfs/etc/issue"
  success "Ubuntu24LTS container created ✔️"
else
  debug "Ubuntu24LTS container already exists"
fi
setup_container_pamtester "Ubuntu24LTS" "ubuntu"

# AlpineEdge container
LXC=`lxc-ls -1 "AlpineEdge" 2>/dev/null`;
if [ "${LXC}" == "" ] ; then
  debug "Creating AlpineEdge container..."
  DOWNLOAD_KEYSERVER="keyserver.ubuntu.com" lxc-create -t download -n AlpineEdge -- -d alpine -r edge -a amd64
  echo "No default login, please use Sandbox to configure a new OS from this" >> "/var/lib/lxc/AlpineEdge/rootfs/etc/issue"
  echo "nameserver 1.1.1.3" > "/var/lib/lxc/AlpineEdge/rootfs/etc/resolv.conf"
  success "AlpineEdge container created ✔️"
else
  debug "AlpineEdge container already exists"
fi
setup_container_pamtester "AlpineEdge" "alpine"

success "All containers configured ✔️"

debug "Increase route cache for ipv6"
PATTERN=`awk '/net.ipv6.route.max_size = 20000/' "/etc/sysctl.conf"`;
if [ "${PATTERN}" == "" ]; then
  echo "net.ipv6.route.max_size = 20000" >> "/etc/sysctl.conf"
fi;
success "IPv6 cache increased ✔️"

debug "Increase lxc capabilities"

PATTERN=`awk '/fs.inotify.max_user_watches=800000/' "/etc/sysctl.conf"`;
if [ "${PATTERN}" == "" ]; then
  echo "fs.inotify.max_user_watches=800000" >> "/etc/sysctl.conf"
fi;

PATTERN=`awk '/fs.inotify.max_user_instances=500000/' "/etc/sysctl.conf"`;
if [ "${PATTERN}" == "" ]; then
  echo "fs.inotify.max_user_instances=500000" >> "/etc/sysctl.conf"
fi;

PATTERN=`awk '/fs.file-max=15793398/' "/etc/sysctl.conf"`;
if [ "${PATTERN}" == "" ]; then
  echo "fs.file-max=15793398" >> "/etc/sysctl.conf"
fi;

PATTERN=`awk '/kernel.pty.max = 10000/' "/etc/sysctl.conf"`;
if [ "${PATTERN}" == "" ]; then
  echo "kernel.pty.max = 10000" >> "/etc/sysctl.conf"
fi;

sysctl -f /etc/sysctl.conf
success "LXC capabilities increased ✔️"

# Create LVM volume group for LXC containers
create_lxc_volume_group

# Configure messenger.yaml if worker_id is specified
if [ -n "$WORKER_ID" ]; then
  configure_messenger_yaml "$WORKER_ID" "$WORKER_IP"
fi

if ! [ -f "${REMOTELABZ_WORKER_PATH}/images/alpinelab1.img" ] ; then
  debug "Download Alpine qemu image"
  wget -q -P "${REMOTELABZ_WORKER_PATH}"/images https://www.remotelabz.com/wp-content/uploads/alpinelab1.img
  success "Alpine image downloaded ✔️"
fi;

chown remotelabz-worker:www-data "${REMOTELABZ_WORKER_PATH}/var" -R
chmod g+w "${REMOTELABZ_WORKER_PATH}/var" -R
chmod g+w /var/lib/lxc
chown remotelabz-worker: "${REMOTELABZ_WORKER_PATH}"/images

systemctl enable remotelabz-worker
systemctl start remotelabz-worker

success "══════════════════════════════════════════════════════════"
success "  RemoteLabz-worker is installed and ready to serve!"
success "══════════════════════════════════════════════════════════"

# Automated SSH configuration between workers
configure_ssh_between_workers

# Smart post-installation summary with automated checks
display_post_installation_actions

echo ""
success "Thank you for using RemoteLabz! ❤️"
echo ""

exit 0
