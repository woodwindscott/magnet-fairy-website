#!/bin/bash
# Pushes the current branch to GitHub (source of truth / history) and to
# Bluehost (live site) in one step. Requires the `bluehost` git remote to be
# configured locally with SSH access — see README.md.
set -e

git push origin main
git push bluehost main

echo "Deployed to https://themagnetfairy.com"
