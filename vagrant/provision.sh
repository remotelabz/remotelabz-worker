#!/bin/sh

export DEBIAN_FRONTEND=noninteractive
# Install packages
apt-get update
apt-get install -y php zip unzip php-curl php-xdebug php-xml qemu libxml2-utils openvswitch-switch git python-pip
# Handle users permissions
sudo groupadd remotelabz-worker
sudo usermod -aG remotelabz-worker vagrant
sudo usermod -aG remotelabz-worker www-data
echo "www-data     ALL=(ALL) NOPASSWD: /bin/ip" | sudo tee /etc/sudoers.d/www-data
# Composer
if ! [ $(command -v composer) ]; then 
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php -r "if (hash_file('sha384', 'composer-setup.php') === '48e3236262b34d30969dca3c37281b3b4bbe3221bda826ac6a9a62d6444cdb0dcd0615698a5cbe587c3f0fe57a54d8f5') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
    php composer-setup.php
    php -r "unlink('composer-setup.php');"
    sudo mv composer.phar /usr/local/bin/composer
fi
(cd /var/www/html/remotelabz-worker && composer install)
# Folders
mkdir -p /opt/remotelabz/images
chmod -R g+rwx /opt/remotelabz
chgrp -R remotelabz-worker /opt/remotelabz
# Websockify
pip install setuptools
git clone https://github.com/novnc/websockify.git /opt/remotelabz/websockify
(cd /opt/remotelabz/websockify/ && python setup.py install)
# Grant OVS permissions to vagrant user
sudo chmod g+rwx /var/run/openvswitch/db.sock
sudo chgrp remotelabz-worker /var/run/openvswitch/db.sock
# Configure apache
sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf
sudo ln -fs /var/www/html/remotelabz-worker/vagrant/100-remotelabz-worker.conf /etc/apache2/sites-enabled/100-remotelabz-worker.conf
sudo service apache2 reload
ln -fs /var/www/html/remotelabz-worker ./remotelabz