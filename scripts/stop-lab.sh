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

    OLD_DIR=$(pwd)
    cd /opt/remotelabz/websockify/
    python setup.py install
    cd "${OLD_DIR}"
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
    ovs-vsctl --if-exists del-br "${BRIDGE_NAME}"
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
    NB_VM=$(xml "count(/lab/device[@hypervisor='qemu'])")
    OVS_NAME=$(xml "/lab/device[@type='switch']/@name")
    BRIDGE_NAME="lab_${LAB_NAME}_${OVS_NAME}"
    
    VM_INDEX=1
    # POSIX Standard
    while [ ${VM_INDEX} -le $((NB_VM)) ]; do
        VM_PATH="/lab/device[@hypervisor='qemu'][${VM_INDEX}]"

        VNC_PORT=$(xml "${VM_PATH}/network_interface/settings/@port")

        PID_WEBSOCKIFY=$(netstat -tnap | grep $((VNC_PORT+1000)) | awk -F "[ /]*" '{print $7}')
        PID_VM=$(netstat -tnap | grep $((VNC_PORT)) | grep qemu | awk -F "[ /]*" '{print $7}')

        if [ "${PID_WEBSOCKIFY}" ]; then
            IFS=$'\n'
            for PID in ${PID_WEBSOCKIFY}
            do
                kill -9 "${PID}"
                echo "Killed websockify process ${PID}"
            done
        else
            echo "No websockify process to kill (PID: ${PID_WEBSOCKIFY})"
        fi

        if [ "${PID_VM}" ]; then
            kill -9 "${PID_VM}"
            echo "Killed process ${PID_VM}"
        else
            echo "No process to kill (PID: ${PID_VM})"
        fi

        NB_NET_INT=$(xml "count(${VM_PATH}/network_interface/@type[1])")

        VM_IF_INDEX=1
        echo -e "Deleting interfaces"
        while [ ${VM_IF_INDEX} -le $((NB_NET_INT)) ]; do
            NET_IF_NAME=$(xml "${VM_PATH}/network_interface[${VM_IF_INDEX}]/@name")

            ovs-vsctl --if-exists --with-iface del-port "${BRIDGE_NAME}" "${NET_IF_NAME}"
            sudo ip link set "${NET_IF_NAME}" down
            sudo ip link delete "${NET_IF_NAME}"
            
            VM_IF_INDEX=$((VM_IF_INDEX+1))
        done

        VM_INDEX=$((VM_INDEX+1))
    done

    rm -rf /opt/remotelabz/"${LAB_USER}"/"${LAB_NAME}"/${VM_INDEX}
}

#####################
# Main
#####################

main() {
    qemu
    # vpn # TODO: Conditional (are we executing on a vpn server?)
    ovs
}

main
exit 0