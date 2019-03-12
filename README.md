# remotelabz-worker

VM-side of RemoteLabz v2 project.

# Requirements

- Vagrant

# Launch

1. Start VM and connect

```bash
vagrant up && vagrant ssh
```

2. Start server

```bash
cd remotelabz
php bin/console server:run 0.0.0.0
```

Server is up at http://localhost:8000.