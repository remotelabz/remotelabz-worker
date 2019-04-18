#!/bin/bash
#
# This script was created by Julien Hubert
# (c) URCA, 2019
#
# TODO: For now, this script aims to execute all the app parts (vm, vpn)
# on the same server. Need to provide a way to dispatch components (API ?)

set -e

# Emulate python script parameters with .env file
# TODO: Use these parameters
#
# ENV_FILE=${PWD}/../.env

# if [ -f ${ENV_FILE} ]; then
#     source ${ENV_FILE}
# else
#     echo "Error: Environment file .env not found in ${ENV_FILE}. Please check this file exists and try again." >&2
#     exit 1
# fi

if ! [ -x "$(command -v xmllint)" ]; then
    echo 'Error: xmllint is not installed. Please install it and try again' >&2
    exit 1
fi

if ! [ -x "$(command -v qemu-system-"$(uname -i)")" ]; then
    echo 'Error: qemu is not installed. Please install it and try again' >&2
    exit 1
fi

if ! [ -x "$(command -v ovs-vsctl)" ]; then
    echo 'Error: openvswitch is not installed. Please install it and try again' >&2
    exit 1
fi

if ! [ -x "$(command -v websockify)" ]; then
    if ! [ -d "/opt/remotelabz/websockify/.git" ]; then
        git clone https://github.com/novnc/websockify.git /opt/remotelabz/websockify
    fi

    (cd /opt/remotelabz/websockify/ && python setup.py install)
    # echo 'Error: openvswitch is not installed. Please install it and try again' >&2
    # exit 1
fi

usage() {
    echo "Usage: $0 [-f <Labfile>] [<xml>]" 1>&2; exit 1;
}

while getopts "f:" OPTION; do
    case ${OPTION} in
        f)
            if ! [ -f "${OPTARG}" ]; then
                echo 'Error: specified lab file not found.' >&2
                exit 1
            fi
            LAB_CONTENT="$(cat "${OPTARG}")"
            ;;
        *)
            ;;
    esac
done

if [ -z "${LAB_CONTENT}" ]; then
    # Relies on for loop, which is looping on args by default
    for CONTENT; do true; done
    LAB_CONTENT="${CONTENT}"
fi

xml() {
    xmllint --xpath "string($1)" - <<EOF
        $LAB_CONTENT
EOF
}

LAB_USER=$(xml /lab/user/@email)
LAB_NAME=$(xml /lab/@name)
BRIDGE_NAME="br-lab-${LAB_NAME}"

#####################
# OVS
#####################
ovs() {
    ovs-vsctl --may-exist add-br "${BRIDGE_NAME}"
    # FIXME: Launching user should have password-less sudo at least on `ip` command
    sudo ip link set "${BRIDGE_NAME}" up
}

#####################
# VPN
#####################

vpn() {
    VPN_ACCESS=$(xml "/lab/tp_access")

    if [ "${VPN_ACCESS}" = "vpn" ]; then
        OVS_IP=$(xml "/lab/device[@type='switch']/vpn/ipv4")

        # echo "${OVS_IP}"

        # TODO: Finish this later
        #
        # network_lab = lab.xpath("/lab/init/network_lab")[0].text
        # network_user = lab.xpath("/lab/init/network_user")[0].text
        # script_addvpn_servvm += "ip route add %s via %s\n"%(network_user,frontend_ip)
        # script_delvpn_servvm += "ip route del %s via %s\n"%(network_user,frontend_ip)
        # addr_servvm = lab.xpath("/lab/init/serveur/IPv4")[0].text
        
        # script_addvpn_frontend += "ip route add %s via %s\n"%(network_lab,addr_servvm)
        # script_addvpn_frontend += "ip route add %s via %s\n"%(network_user,addr_vpn)
        # script_delvpn_frontend += "ip route del %s via %s\n"%(network_lab,addr_servvm)
        # script_delvpn_frontend += "ip route del %s via %s\n"%(network_user,addr_vpn)

        # script_addvpn_servvpn += "ip route add %s via %s\n"%(network_lab,addr_host_vpn)

        # script_delvpn_servvpn += "ip route del %s via %s\n"%(network_lab,addr_host_vpn)
        
        # Ajouter une connexion internet aux machines - Mise en place d'un patch entre l'OVS du system et l'OVS du lab

        # script_addnet = "ovs-vsctl -- add-port %s patch-ovs%s-0 -- set interface patch-ovs%s-0 type=patch options:peer=patch-ovs0-%s -- add-port br0 patch-ovs0-%s  -- set interface patch-ovs0-%s type=patch options:peer=patch-ovs%s-0\n"%(ovs_name,ovs_name,ovs_name,ovs_name,ovs_name,ovs_name,ovs_name)
        # script_addnet += "iptables -t nat -A POSTROUTING -s %s -o br0 -j MASQUERADE"%network_lab
        # script_delnet += "ovs-vsctl del-port patch-ovs%s-0\n"%ovs_name
        # script_delnet += "ovs-vsctl del-port patch-ovs0-%s\n"%ovs_name
        # script_delnet += "iptables -t nat -D POSTROUTING -s %s -o br0 -j MASQUERADE"%network_lab
    fi
}

#####################
# QEMU
#####################

qemu() {
    NB_VM=$(xml "count(/lab/device[@type='vm' and @hypervisor='qemu'])")
    # VNC_PORT_INDEX=$(xml "/lab/init/serveur/index_interface")
    
    VM_INDEX=1
    # POSIX Standard
    while [ ${VM_INDEX} -le $((NB_VM)) ]; do
        echo "Creating virtual machine number ${VM_INDEX} image for lab ${LAB_NAME}..."
        VM_PATH="/lab/device[@type='vm' and @hypervisor='qemu'][${VM_INDEX}]"
        IMG_SRC=$(xml "${VM_PATH}/operating_system/@image")

        mkdir -p /opt/remotelabz/"${LAB_USER}"/"${LAB_NAME}"/${VM_INDEX}

        if [[ ${IMG_SRC} =~ (http://|https://).* ]]; then
            echo "Downloading image from ${IMG_SRC}..."
            (cd /opt/remotelabz/images/ && curl -s -O "${IMG_SRC}")
            IMG_SRC=$(basename "${IMG_SRC}")
        fi

        IMG_DEST="/opt/remotelabz/${LAB_USER}/${LAB_NAME}/${VM_INDEX}/${IMG_SRC}"
        IMG_SRC="/opt/remotelabz/images/${IMG_SRC}"

        echo "Creating image ${IMG_DEST} from ${IMG_SRC}... "
        # TODO: Pass image formatting as a parameter?
        qemu-img create \
            -f qcow2 \
            -b "${IMG_SRC}" \
            "${IMG_DEST}"
        echo "Done !"

        SYS_PARAMS="-m $(xml "${VM_PATH}/flavor/@memory") -hda ${IMG_DEST} "

        NB_NET_INT=$(xml "count(${VM_PATH}/network_interface/@type[1])")
        
        VM_IF_INDEX=1
        NET_PARAMS=""
        echo "Creating network interfaces..."
        while [ ${VM_IF_INDEX} -le $((NB_NET_INT)) ]; do
            NET_IF_NAME=$(xml "${VM_PATH}/network_interface[${VM_IF_INDEX}]/@name")
            
            echo "Creating network interface \"${NET_IF_NAME}\" (number ${VM_IF_INDEX})..."

            if sudo ip link show "${NET_IF_NAME}" > /dev/null; then
                echo "WARNING: tap ${NET_IF_NAME} already exists."
            else
                sudo ip tuntap add name "${NET_IF_NAME}" mode tap
            fi
            sudo ip link set "${NET_IF_NAME}" up
            ovs-vsctl --may-exist add-port "${BRIDGE_NAME}" "${NET_IF_NAME}"
            
            NET_MAC_ADDR=$(xml "${VM_PATH}/network_interface[${VM_IF_INDEX}]/@mac_address")
            NET_PARAMS="${NET_PARAMS}-net nic,macaddr=${NET_MAC_ADDR} -net tap,ifname=${NET_IF_NAME},script=no "

            VM_IF_INDEX=$((VM_IF_INDEX+1))
        done

        # TODO: add path to proxy
        # script_addpath2proxy += "curl -H \"Authorization: token $CONFIGPROXY_AUTH_TOKEN\" -X POST -d '{\"target\": \"ws://%s:%s\"}' http://localhost:82/api/routes/%s\n"%(vnc_addr,int(vnc_port)+1000,name.replace(" ","_"))

        if [ "VNC" = "$(xml "${VM_PATH}/network_interface/settings/@protocol")" ]; then
            VNC_ADDR=$(xml "${VM_PATH}/network_interface/settings/@ip")
            if [ "" = "${VNC_ADDR}" ]; then
                VNC_ADDR="0.0.0.0"
            fi
            VNC_PORT=$(xml "${VM_PATH}/network_interface/settings/@port")

            # WebSockify
            # TODO: Add a condition
            /opt/remotelabz/websockify/run -D "${VNC_ADDR}":$((VNC_PORT+1000)) "${VNC_ADDR}":"${VNC_PORT}"

            VNC_PORT=$((VNC_PORT-5900))

            ACCESS_PARAMS="-vnc ${VNC_ADDR}:${VNC_PORT}"
            LOCAL_PARAMS="-k fr"
        else
            # ACCESS_PARAMS="-vnc ${VNC_ADDR}:$((VNC_PORT_INDEX+VM_INDEX)),websocket=${VNC_PORT}"
            ACCESS_PARAMS=""
            LOCAL_PARAMS=""
        fi

        LOCAL_PARAMS="${LOCAL_PARAMS} -localtime"

        # Launch VM
        QEMU_COMMAND="qemu-system-$(uname -i) \
            -machine accel=kvm:tcg \
            -cpu Opteron_G2 \
            -daemonize \
            -name $(xml "${VM_PATH}/@name") \
            ${SYS_PARAMS} \
            ${NET_PARAMS} \
            ${ACCESS_PARAMS}\
            ${LOCAL_PARAMS}"

        eval "${QEMU_COMMAND}"

        VM_INDEX=$((VM_INDEX+1))
    done
}

#####################
# Main
#####################

main() {
    ovs
    #vpn # TODO: Conditional (are we executing on a vpn server?)
    qemu
}

# TODO: Script reboot
# TODO: Script delete

main
echo 'OK'
exit 0