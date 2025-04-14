# remotelabz-worker

VM-side of RemoteLabz v3 project (Symfony 6.4).

# Requirements

- Ubuntu 20.04

# Install

```bash
# Clone this project
git clone https://gitlab.remotelabz.com/crestic/remotelabz-worker.git
# Go to the directory
cd remotelabz-worker
# grant the right to execute
sudo chmod +x install
# Launch the installation script (sudo is required !)
sudo ./install
```

If it is specified, you can remove the source folder :

```bash
cd ../ && rm -rf remotelabz-worker
```

## Options

- `-p` Port used by remotelabz-worker
  - Default : `8080`
