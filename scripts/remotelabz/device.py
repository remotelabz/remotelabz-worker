from remotelabz.hypervisor.qemu import QEMU

class Device:
    def __init__(self, _id, _name, _type, _hypervisor, _network_interfaces, _operating_system, _flavor):
        self.id = _id
        self.name = _name
        self.device_type = _type
        self.hypervisor = _hypervisor
        self.network_interfaces = _network_interfaces
        self.operating_system = _operating_system
        self.flavor = _flavor
    
    def start(self):
        hypervisor = QEMU()
        hypervisor.start_vm(self)

def zeaza():
    pass