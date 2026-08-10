#!/usr/bin/env bash
set -euo pipefail

sudo dnf update -y
sudo dnf install -y dnf-plugins-core git curl firewalld
sudo dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
sudo dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

sudo systemctl enable --now docker
sudo systemctl enable --now firewalld

if ! groups "$USER" | grep -q '\bdocker\b'; then
  sudo usermod -aG docker "$USER"
fi

echo "Docker is installed. Log out and back in once so your user can run docker without sudo."
docker --version || true
docker compose version || true
