#!/bin/bash

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Starting data fetch process..."
echo "Project directory: $PROJECT_DIR"

# Pull latest changes
echo "Pulling latest changes..."
cd "$PROJECT_DIR"
git pull

if [ $? -ne 0 ]; then
    echo "Warning: Failed to pull latest changes, continuing anyway..."
fi

# Execute the update script
php "$SCRIPT_DIR/crawl_update.php"

if [ $? -ne 0 ]; then
    echo "Error: Update script failed"
    exit 1
fi

echo "Update completed successfully"

# Check if there are any changes
if [[ -z $(git status -s) ]]; then
    echo "No changes to commit"
    exit 0
fi

echo "Changes detected, committing and pushing..."

git add -A
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
git commit -m "Auto update: $TIMESTAMP"

git push

if [ $? -eq 0 ]; then
    echo "Successfully pushed changes to remote"
else
    echo "Error: Failed to push changes"
    exit 1
fi

echo "Cron job completed successfully"
