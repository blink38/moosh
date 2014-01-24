#!/bin/bash
source config.sh

mysql -u "$DBUSER" -p"$DBPASSWORD" "$DBNAME" < "$DBNAME".sql
cd /var/www

moosh user-create --password pass1234 --email me@example.com --city Szczecin\
 --country PL --firstname bruce --lastname wayne batman
if moosh user-list | grep batman; then
  exit 0
else
  exit 1
fi
