# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure("2") do |config|
  config.vm.box = "generic/ubuntu1804"

  config.vm.hostname = "remotelabz-worker"

  config.vm.network "forwarded_port", guest: 8080, host: 8080

  config.vm.synced_folder ".", "/opt/remotelabz-worker"

  config.vm.provision "shell", path: "install"
end
