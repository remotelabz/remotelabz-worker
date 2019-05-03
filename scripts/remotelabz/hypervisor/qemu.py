import subprocess
from remotelabz.device import Device

class QEMU:
    def __init__(self):
        arch = subprocess.run(["uname", "-p"], capture_output=True)
        self.command = "qemu-system-" + arch.stdout.decode('utf-8').rstrip()

    def start_vm(self, device):
        pass