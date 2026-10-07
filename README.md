# remotelabz-worker

VM-side of RemoteLabz v3 project (Symfony 6.4).

# Requirements

- Ubuntu 20.04

# Install

```bash
# Clone this project
git clone https://github.com/remotelabz/remotelabz-worker.git --branch dev
# Go to the directory
cd remotelabz-worker
# grant the right to execute
sudo chmod +x bin/install.sh
# Launch the installation script (sudo is required !)
sudo .bin/install.sh
```

If it is specified, you can remove the source folder :

```bash
cd ../ && rm -rf remotelabz-worker
```

## Options

- `-p` Port used by remotelabz-worker
  - Default : `8080`
