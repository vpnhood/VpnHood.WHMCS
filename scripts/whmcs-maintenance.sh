#!/bin/bash
# Turns WHMCS maintenance mode on or off straight in the database (works while the files and the
# database disagree on the version, when WHMCS itself will not start).
# usage: ssh <user>@... 'bash -s -- <webroot> on|off' < whmcs-maintenance.sh
set -euo pipefail
cd "$1"
VALUE=$([ "$2" = on ] && echo on || echo '')
php -r 'include "configuration.php"; $m = new mysqli($db_host, $db_username, $db_password, $db_name);
  $m->query("UPDATE tblconfiguration SET value=\x27'"$VALUE"'\x27 WHERE setting=\x27MaintenanceMode\x27");
  echo "MaintenanceMode=\x27", $m->query("SELECT value FROM tblconfiguration WHERE setting=\x27MaintenanceMode\x27")->fetch_row()[0], "\x27\n";'
