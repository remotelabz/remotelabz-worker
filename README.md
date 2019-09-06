# remotelabz-worker

VM-side of RemoteLabz v2 project.

# Requirements

- Ubuntu 18.04

# Install

```bash
# Clone this project
git clone https://gitlab.remotelabz.com/crestic/remotelabz-worker.git
# Go to the directory
cd remotelabz-worker
# Launch the installation script (sudo is required !)
sudo ./install.sh
```

If it is specified, you can remove the source folder :
```bash
cd ../ && rm -rf remotelabz-worker
```

## Options

- `-d` Directory where remotelabz-worker will be installed.
    - Default : `/var/www/html/remotelabz-worker`
- `-p` Port used by remotelabz-worker
    - Default : `8080`
