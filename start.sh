env_file=envs/dev.sh

if [ -f "$env_file" ]; then
    . ./"$env_file"
else
  echo "Environment does not exist from chosen file. File \"$env_file\" is missing"
fi

php -S 127.0.0.1:8000 -c /home/filbert/Documents/UV_LOCAL/php.ini