import subprocess
from remotelabz.objects import Device

class QEMU:
    def __init__(self):
        arch = subprocess.run(["uname", "-p"], capture_output=True)
        self.command = "qemu-system-" + arch.stdout.decode('utf-8').rstrip()

    def start_vm(self, device):
        command = (self.command + " " +
            self.command)
        return subprocess.run(command, shell=True, capture_output=True)