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

SCRIPT=$(readlink -f "$0")
SCRIPT_DIR=$(dirname "${SCRIPT}")
WORKER_DIR="${SCRIPT_DIR}/.."

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
    echo 'Error: websockify is not installed. Please install it and try again' >&2
    exit 1
fi

usage() {
    echo "Usage: $0 [-f <Labfile>] [-d <device_uuid>] [<xml>]" 1>&2; exit 1;
}

START_DEVICE=false

while getopts "f:d:" OPTION; do
    case ${OPTION} in
        f)
            if ! [ -f "${OPTARG}" ]; then
                echo 'Error: specified lab file not found.' >&2
                exit 1
            fi
            LAB_CONTENT="$(cat "${OPTARG}")"
            ;;
        d)
            START_DEVICE=true
            DEVICE_UUID="${OPTARG}"
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

LAB_NAME=$(xml /lab/@name)
BRIDGE_UUID=$(xml "/lab/instance/@uuid")
BRIDGE_NAME="br-$(echo ${BRIDGE_UUID} | cut -c-8)"

#####################
# OVS
#####################
ovs() {
    ovs-vsctl --may-exist add-br "${BRIDGE_NAME}"
    # FIXME: Launching user should have password-less sudo at least on `ip` command
    
    ADDRESS_SET=$(ip addr show dev "${BRIDGE_NAME}");

    if [ "${ADDRESS_SET}" != "" ];
    then
       sudo ip addr add $(echo ${LAB_NETWORK} | cut -d. -f1-3).254/24 dev ${BRIDGE_NAME};
    fi;

    sudo ip link set "${BRIDGE_NAME}" up;
}

create_network_interfaces() {
    NB_NET_INT=$(xml "count(${VM_PATH}/network_interface)")
    VM_IF_INDEX=1
    NET_PARAMS=""
    echo "Creating network interfaces..."
    while [ ${VM_IF_INDEX} -le $((NB_NET_INT)) ]; do
        NET_IF_NAME=$(xml "${VM_PATH}/network_interface[${VM_IF_INDEX}]/@name" | tr -d '[:space:]' | cut -c-6)
        NET_IF_UUID=$(xml "${VM_PATH}/network_interface[${VM_IF_INDEX}]/instance/@uuid" | tr -d '[:space:]' | cut -c-8)
        NET_IF_NAME="${NET_IF_NAME}-${NET_IF_UUID}"
        
        echo "Creating network interface \"${NET_IF_NAME}\" (number ${VM_IF_INDEX})..."

        if sudo ip link show "${NET_IF_NAME}" 2> /dev/null; then
            echo "WARNING: tap ${NET_IF_NAME} already exists."
        else
            sudo ip tuntap add name "${NET_IF_NAME}" mode tap
        fi
        ovs-vsctl --may-exist add-port "${BRIDGE_NAME}" "${NET_IF_NAME}"
        sudo ip link set "${NET_IF_NAME}" up
        
        NET_MAC_ADDR=$(xml "${VM_PATH}/network_interface[${VM_IF_INDEX}]/@mac_address")
        NET_PARAMS="${NET_PARAMS}-net nic,macaddr=${NET_MAC_ADDR} -net tap,ifname=${NET_IF_NAME},script=no "

        VM_IF_INDEX=$((VM_IF_INDEX+1))
    done
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
    if ${START_DEVICE}; then
        qemu_start_vm "${DEVICE_UUID}"
    else
        NB_VM=$(xml "count(/lab/device[@type='vm' and @hypervisor='qemu' and instance/@is_started='false'])")
        
        VM_INDEX=1
        while [ ${VM_INDEX} -le $((NB_VM)) ]; do
            DEVICE_UUID=$(xml "/lab/device[@type='vm' and @hypervisor='qemu' and instance/@is_started='false'][${VM_INDEX}]/@uuid")

            qemu_start_vm "${DEVICE_UUID}"

            VM_INDEX=$((VM_INDEX+1))
        done
    fi
}

qemu_start_vm() {
    echo "Creating virtual machine UUID ${DEVICE_UUID} for lab ${LAB_NAME}..."

    VM_PATH="/lab/device[@type='vm' and @hypervisor='qemu' and @uuid='${DEVICE_UUID}' and instance/@is_started='false']"

    INSTANCE_UUID=$(xml "${VM_PATH}/instance/@uuid")
    LAB_USER=$(xml "/lab/instance/@user_id")
    LAB_UUID=$(xml "/lab/@uuid")
    IMG_SRC=$(xml "${VM_PATH}/operating_system/@image")

    mkdir -p ${WORKER_DIR}/instances/"${LAB_USER}"/"${LAB_UUID}"/${DEVICE_UUID}

    # TODO: script shouldn't download image, it should be done via php instead
    if [[ ${IMG_SRC} =~ (http://|https://).* ]]; then
        if [ ! -f ${WORKER_DIR}/images/$(basename "${IMG_SRC}") ]; then
            echo "Downloading image from ${IMG_SRC}..."
            (cd ${WORKER_DIR}/images/ && curl -s -O "${IMG_SRC}")
        else
            echo "WARNING: Image was already downloaded. Skipping download."
        fi
        IMG_SRC=$(basename "${IMG_SRC}")
    fi

    IMG_DEST="${WORKER_DIR}/instances/${LAB_USER}/${LAB_UUID}/${DEVICE_UUID}/${IMG_SRC}"
    IMG_SRC="${WORKER_DIR}/images/${IMG_SRC}"

    echo "Creating image ${IMG_DEST} from ${IMG_SRC}... "
    # TODO: Pass image formatting as a parameter?
    qemu-img create \
        -f qcow2 \
        -F qcow2 \
        -b "${IMG_SRC}" \
        "${IMG_DEST}"
    echo "Done !"

    SYS_PARAMS="-m $(xml "${VM_PATH}/flavor/@memory") -hda \"${IMG_DEST}\" "
    
    create_network_interfaces

    if [ "VNC" = "$(xml "${VM_PATH}/network_interface/settings/@protocol")" ]; then
        VNC_ADDR=$(xml "${VM_PATH}/network_interface/settings/@ip")
        if [ "" = "${VNC_ADDR}" ]; then
            VNC_ADDR="0.0.0.0"
        fi
        VNC_PORT=$(xml "${VM_PATH}/network_interface/instance/@remote_port")

        # WebSockify
        websockify -D "${VNC_ADDR}":$((VNC_PORT+1000)) "${VNC_ADDR}":"${VNC_PORT}"

        VNC_PORT=$((VNC_PORT-5900))

        ACCESS_PARAMS="-vnc ${VNC_ADDR}:${VNC_PORT}"
        LOCAL_PARAMS="-k fr"
    else
        # ACCESS_PARAMS="-vnc ${VNC_ADDR}:$((VNC_PORT_INDEX+VM_INDEX)),websocket=${VNC_PORT}"
        ACCESS_PARAMS=""
        LOCAL_PARAMS=""
    fi

    LOCAL_PARAMS="${LOCAL_PARAMS} -localtime -smp 4 -vga qxl"

    # Launch VM
    QEMU_COMMAND="qemu-system-$(uname -i) \
        -machine accel=kvm:tcg \
        -enable-kvm \
        -cpu max \
        -display none \
        -daemonize \
        -name ${INSTANCE_UUID} \
        ${SYS_PARAMS} \
        ${NET_PARAMS} \
        ${ACCESS_PARAMS}\
        ${LOCAL_PARAMS}"

    eval "${QEMU_COMMAND}"
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
