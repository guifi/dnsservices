#
# Regular cron jobs for the dnsservices package
#

*/30 * * * *	root	cd /etc/bind && /usr/bin/php /usr/share/dnsservices/dnsservices.php >> /var/log/dnsservices/dnsservices.log && /etc/init.d/bind9 reload
