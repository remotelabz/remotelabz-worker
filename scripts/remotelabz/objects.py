from remotelabz.hypervisor.qemu import QEMU

class NetworkSettings:
    def __init__(self, ip, ipv6, prefix, prefix6, gateway, protocol, port):
        self.ip = ip
        self.ipv6 = ipv6
        self.prefix = prefix
        self.prefix6 = prefix6
        self.gateway = gateway
        self.protocol = protocol
        self.port = port

class NetworkInferface:
    def __init__(self, interface_type, name, mac_address, settings):
        self.interface_type = interface_type
        self.name = name
        self.mac_address = mac_address
        self.settings = settings

class Device:
    def __init__(self, id, name, device_type, hypervisor, network_interfaces, operating_system, flavor):
        self.id = id
        self.name = name
        self.device_type = device_type
        self.hypervisor = hypervisor
        self.network_interfaces = network_interfaces
        self.operating_system = operating_system
        self.flavor = flavor
    
    def start(self):
        if self.device_type == "vm":
            if self.hypervisor == "qemu":
                hypervisor = QEMU()

            if self.hypervisor:
                hypervisor.start_vm(self)

def test():
    pass