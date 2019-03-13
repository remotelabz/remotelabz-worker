# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure("2") do |config|
  config.vm.box = "ubuntu/bionic64"

  config.vm.network "forwarded_port", guest: 8000, host: 8000
  config.vm.provision "file", source: "./vagrant/100-remotelabz-worker.conf", destination: "/etc/apache2/sites-available/100-remotelabz-worker.conf"

  config.vm.provision "shell", inline: <<-SHELL
    sudo apt-get update
    sudo apt-get install -y php zip unzip php-curl php-xml qemu libxml2-utils openvswitch-switch git python-pip
    sudo groupadd remotelabz-worker
    sudo usermod -aG remotelabz-worker vagrant
    sudo usermod -aG remotelabz-worker www-data
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php -r "if (hash_file('sha384', 'composer-setup.php') === '48e3236262b34d30969dca3c37281b3b4bbe3221bda826ac6a9a62d6444cdb0dcd0615698a5cbe587c3f0fe57a54d8f5') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
    php composer-setup.php
    php -r "unlink('composer-setup.php');"
    sudo mv composer.phar /usr/local/bin/composer
    ln -fs /vagrant/ ./remotelabz
    pip install setuptools
    # Grant OVS permissions to vagrant user
    sudo chmod g+rwx /var/run/openvswitch/db.sock
    sudo chgrp remotelabz-worker /var/run/openvswitch/db.sock
    # Configure apache
    sudo ln -s /etc/apache2/sites-available/100-remotelabz-worker.conf /etc/apache2/sites-enabled/100-remotelabz-worker.conf
    sudo ln -s /vagrant /var/www/html/remotelabz-worker
    sudo service apache2 reload
  SHELL
end
