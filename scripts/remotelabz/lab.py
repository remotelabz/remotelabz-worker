import remotelabz.device

class Lab:
    def __init__(self, id, name, devices):
        self.id = id
        self.name = name
        self.devices = [] + devices
    
    def append(self, device):
        self.devices.append(device)