<?php

function check_cnml($cnml) {
  $header = substr($cnml,0,5);
    if ($header == '<?xml') {
      return true;
    }
    else {
      echo date("YmdHi")." Invalid CNML! cannot update! check master cnml server url on /etc/dnservices/config.php\nor check your Internent/Guifi.net connection.\n named.conf not changed! \n";
      exit();
    }
}

  function check_updated($url) {
    global $DNSGraphServerId;

    $now = time();
    $mlast= @fopen("/tmp/last_dns", "r");
      if ($mlast)
        $last = fgets($mlast);
     else
        $last = 0;
     $mins = $DNSGraphServerId % 30;
     $fresh = $last +  ((60 + $mins) * 60);
     print "Last time updated: ".date('Y/m/d H:i:s',(int)$last)."\n";
     print "Fresh until:       ".date('Y/m/d H:i:s',(int)$fresh)."\n";

     if (($last) and ($now < $fresh)) {
       fclose($mlast);
       echo "Still fresh.\n";
       exit();
     }

    $hlastnow = @fopen($url."/guifi/refresh/dns", "r") or die('Error reading changes\n');
    $last_now = fgets($hlastnow);
    fclose($hlastnow);
    $hlast= @fopen("/tmp/last_update.dns", "r");
    if (($hlast) and ($last_now == fgets($hlast))) {
      fclose($hlast);
      echo "No domain and hosts changes.\n";
      $hlast= @fopen("/tmp/last_dns", "w+") or die('Error!');
      fwrite($hlast,$now);
      fclose($hlast);
      exit();
   }
   $hlast= @fopen("/tmp/last_update.dns", "w+") or die('Error!');
   fwrite($hlast,$last_now);
   fclose($hlast);
   $hlast= @fopen("/tmp/last_dns", "w+") or die('Error!');
   fwrite($hlast,$now);
   fclose($hlast);

   echo $last_now." updated!\n";
}


class BIND {
  var $PROGRAM = "dnsservices";
  var $VERSION = "1.1.13";
  var $DATE;
  var $h_named;
  var $h_db;
  var $serial;
  var $named_conf = "named.conf";
  var $master_dir;
  var $slave_dir;
  var $chroot;

  function BIND($master, $slave, $chr) {
    $this->DATE = date("YmdHi");
    $this->serial = date("YmdH");
    $this->master_dir = $master;
    $this->slave_dir = $slave;
    $this->chroot = $chr;
    return;
  }

  function named_options() {
    $file = <<<EOF
options {
        directory "$this->slave_dir/";
        auth-nxdomain no;    # conform to RFC103
	allow-query { any; };
	recursion no;
	listen-on { any; };
	listen-on-v6 { any; };
};

include "$this->master_dir/named.conf.options.private";

EOF;
    fwrite($this->h_named, $file);
  }

  function add_default_named() {
    $file="
;
; BIND data file for local loopback interface
;
\$TTL	604800
@	IN	SOA	localhost. root.localhost. (
			2 604800 86400 2419200 604800 )
;
@	IN	NS	localhost.
@	IN	A	127.0.0.1
@	IN	AAAA	::1
";
    $h = fopen($this->chroot.$this->master_dir."/named.local", "w");
    fwrite($h, $file);
    fclose($h);

    $file="
;
; BIND reverse data file for local loopback interface
;
\$TTL	604800
@	IN	SOA	localhost. root.localhost. (
			1 604800 86400 2419200 604800 )
;
@	IN	NS	localhost.
1.0.0	IN	PTR	localhost.
";
    $h = fopen($this->chroot.$this->master_dir."/named.127", "w");
    fwrite($h, $file);
    fclose($h);

    $file=";
; BIND reverse data file for broadcast zone
;
\$TTL	604800
@	IN	SOA	localhost. root.localhost. (
			1 604800 86400 2419200 604800 )
;
@	IN	NS	localhost.
";
    $h = fopen($this->chroot.$this->master_dir."/named.0", "w");
    fwrite($h, $file);
    fclose($h);

    $file="
;
; BIND reverse data file for broadcast zone
;
\$TTL	604800
@	IN	SOA	localhost. root.localhost. (
			1 604800 86400 2419200 604800 )
;
@	IN	NS	localhost.
";

    $h = fopen($this->chroot.$this->master_dir."/named.255", "w");
    fwrite($h, $file);
    fclose($h);

    $cnml = "";
    $h = fopen("http://www.internic.net/zones/named.root", "r") or die(date("YmdHi")." Unable to fetch named.root.\n");
    while (!feof($h)) { $cnml .= fgets( $h ) or die(date("YmdHi")." .Unable to read CNML.\n"); }
    fclose( $h );
    $h = fopen($this->chroot.$this->master_dir."/named.root", "w");
    fwrite($h, $cnml);
    fclose($h);

  }

  function view_ini($name, $opt) {
    $op = <<<EOF
view "$name" {
$opt

EOF;

    fwrite($this->h_named, $op);
  }

  function view_end() {
    fwrite($this->h_named,"};\n");
  }

  function db_ini($domain, $nameserver, $contact) {
    $this->h_db = fopen($this->chroot.$this->master_dir."/".$domain, "w");
    $this->add_db_header($nameserver, $domain, $contact);
  }

  function db_end() {
    fwrite($this->h_db, "\n; End of configuration\n\n");
    fclose($this->h_db);
  }

  function zone($name, $domain, $type, $list, $transfer) {
    $op = "";
    if ($name!="")
      $n = "internet".".".$domain;
    else
      $n = $domain;
    switch($type){
    case "master":
      $op .= "file \"".$this->master_dir."/".$n."\"; notify yes; allow-transfer { ".$transfer."; };";
      break;
    case "slave":
      $op .= "file \"".$this->slave_dir."/".$n."\"; ";
      $op .= "masters { ";
      $masters = explode(",",$list);
      foreach ($masters as $ip) {
        $op .= $ip."; ";
      }
      $op .= "};";
      break;
    case "forward":
      $op .= "forward first; ";
      $op .= "forwarders { ";
      $masters = explode(",",$list);
      foreach ($masters as $ip) {
        $op .= $ip."; ";
      }
      $op .= "};";
      break;
    }

    $file = <<<EOF
	zone "$domain" IN { type $type; $op };

EOF;
	fwrite($this->h_named, $file);
  }

  function named() {

    $this->h_named = fopen($this->named_conf, "w");

    $file = <<<EOF

//
// named.conf - generated by $this->PROGRAM v$this->VERSION @ $this->DATE
//


EOF;

    fwrite($this->h_named, $file);

    $this->named_options();

    $this->add_default_named();
  }

  function add_default_zones($name) {
    if ($name == "")
      $file = <<<EOF
	zone "." IN { type hint; file "$this->master_dir/named.root"; };
	zone "localhost" IN { type master; file "$this->master_dir/named.local"; };
	zone "127.in-addr.arpa" IN { type master; file "$this->master_dir/named.127"; };
	zone "0.in-addr.arpa" IN { type master; file "$this->master_dir/named.0"; };
	zone "255.in-addr.arpa" IN { type master; file "$this->master_dir/named.255"; };

	zone "ip.guifi.net" IN { type master; file "$this->master_dir/ip.guifi.net"; allow-update { none; }; };
	zone "10.in-addr.arpa" IN { type master; file "$this->master_dir/10.ip.guifi.net.rrz"; allow-update { none; }; };
	zone "172.in-addr.arpa" IN { type master; file "$this->master_dir/172.ip.guifi.net.rrz"; allow-update { none; }; };

EOF;
    else
      $file = <<<EOF
	zone "." IN { type hint; file "$this->master_dir/named.root"; };

EOF;

    fwrite($this->h_named, $file);
}

  function addtabs($str) {
    $len=strlen($str)/8;
    for ($i=0;$i<4-$len;$i++)
      $str.="\t";

    return $str;
  }

  function add_db_header($nameserver, $zone, $email) {

    list($user,$domain) = explode('@',$email,2);
    $user = str_replace(".", "\.", $user);
    $contact = $user.".".$domain;

    //fwrite($this->h_db,"\$TTL 38400\n");
    fwrite($this->h_db,/*"\$ORIGIN $dtail.\n"*/ "@\tIN\tSOA\t$nameserver.$zone. $contact. (\n");

    $refresh = 43200;
    $retry = 7200;
    $expire = 1209600;
    $ttl = 7200;
    fwrite($this->h_db, "\t\t$this->serial $refresh $retry $expire $ttl )\n");
  }

  function txt_NS() {
    fwrite($this->h_db, "\n; DNS Servers\n");
  }

  function add_NS($hosts, $ips) {
    if ($hosts != "" && $ips != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        $host = $this->addtabs($host);
        $_ips = explode(",",$ips);
        foreach ($_ips as $ip) {
          fwrite($this->h_db,"$host\tIN\tNS\t\t$ip.\n");
        }
      }
    }
  }

  function txt_extNS() {
    fwrite($this->h_db, "\n; Domain External Namservers Records\n");
  }

  function add_extNS($hosts, $ips) {
    if ($hosts != "" && $ips != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        $host = $this->addtabs($host);
        $_ips = explode(",",$ips);
        foreach ($_ips as $ip) {
          fwrite($this->h_db,"$host\tIN\tNS\t\t$ip.\n");
        }
      }
    }
  }
  function txt_A() {
    fwrite($this->h_db, "\n; Hosts/A Records\n");
  }

  function txt_AAAA() {
    fwrite($this->h_db, "\n; Hosts/AAAA Records\n");
  }

  function add_A($hosts, $ips) {
    if ($hosts != "" && $ips != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        $host = $this->addtabs($host);
        $_ips = explode(",",$ips);
        foreach ($_ips as $ip) {
          fwrite($this->h_db,"$host\tIN\tA\t\t$ip\n");
        }
      }
    }
  }

  function add_AAAA($hosts, $ips) {
    if ($hosts != "" && $ips != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        $host = $this->addtabs($host);
        $_ips = explode(",",$ips);
        foreach ($_ips as $ip) {
          fwrite($this->h_db,"$host\tIN\tAAAA\t\t$ip\n");
        }
      }
    }
  }

  function txt_CNAME() {
    fwrite($this->h_db, "\n; Aliases/CNAME Records\n");
  }

  function add_CNAME($hosts, $domain, $mdomain) {
    if ($hosts != "" && $domain != "" && $mdomain != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        if ($host[strlen($host)-1] != ".")
          fwrite($this->h_db,$this->addtabs($host)."\tIN\tCNAME\t\t".$domain.".".$mdomain.".\n");
      }
    }
  }

  function txt_CNAMEEXT() {
    fwrite($this->h_db, "\n; Aliases/CNAME Records to an external domains.\n");
  }

  function add_CNAMEEXT($hosts, $domain, $mdomain) {
    if ($hosts != "" && $domain != "" && $mdomain != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        //$host = $this->addtabs($host);
        ////echo strlen($mdomain)."\n";
        if ($host[strlen($host)-1] == ".")
          fwrite($this->h_db,$this->addtabs($domain)."\tIN\tCNAME\t\t".$host."\n");
      }
    }
  }

  function txt_DELEGATIONS() {
    fwrite($this->h_db, "\n; Delegations\n");
  }

  function txt_MX() {
    fwrite($this->h_db, "\n; MailServer/MX Records\n");
  }

  function add_MX($hosts, $ips, $priority = "") {
    if ($hosts != "" && $ips != "") {
      $hosts = explode(",",$hosts);
      foreach ($hosts as $host) {
        $host = $this->addtabs($host);
        $_ips = explode(",",$ips);
        foreach ($_ips as $ip) {
          fwrite($this->h_db,"$host\tIN\tMX\t$priority\t$ip.\n");
        }
      }
    }
  }

  function txt_extMX() {
    fwrite($this->h_db, "\n; External MailServer/MX Records\n");
  }

  function add_extMX($extmx, $ips, $priority) {
    if ($extmx != "") {
      $extmx = explode(",",$extmx);
      foreach ($extmx as $mx) {
        $mx = $this->addtabs($mx);
        $_ips = explode(",",$ips);
        foreach ($_ips as $ip) {
         $priority = $priority+10;
          fwrite($this->h_db,"$mx\tIN\tMX\t$priority\t$ip.\n");
        }
      }
    }
  }
} // end BIND


class DNSservices {

  var $cnml_host;
  var $master_dir;
  var $slave_dir;
  var $chroot;

  function DNSservices($host,$service_id,$master,$slave,$zcd){
    $this->cnml_host = $host;
    $this->service_id = $service_id;
    $this->master_dir = $master;
    $this->slave_dir = $slave;
    $this->chroot = $zcd;
  }

  function view($_name,$op,$domains,$dns) {
    if ($_name != "")
      $dns->view_ini("external", $op);
    else
      $dns->view_ini("internal", $op);


    $dns->add_default_zones($_name);

    foreach ($domains->master as $Domain) {
        $dns->zone($_name,$Domain['zone'], "master", "",$Domain['allow-transfer']);
        if ($_name != "")
          $n = $_name.".".$Domain["zone"];
        else
          $n = $Domain["zone"];

          $dns->db_ini($n, $Domain['nameserver'], $Domain['contact']);

          if ($Domain['domain_ip'] != "") {
            $dns->add_A("*", $Domain['domain_ip']);
            $dns->add_A("@", $Domain['domain_ip']);
          }
          if ($Domain['domain_ip_v6'] != "") {
            $dns->add_AAAA("*", $Domain['domain_ip_v6']);
            $dns->add_AAAA("@", $Domain['domain_ip_v6']);
          }

          $dns->txt_NS();
          foreach ($Domain->host as $host) {
            if ($host['NS'] == "y") {
              $dns->add_NS("@", $host['name'].".".$Domain['zone']);
            }
          }
          $dns->txt_extNS();
          if ($Domain['externalNS'] != "") {
            $dns->add_extNS("@", $Domain['externalNS']);
          }

          $dns->txt_MX();
          foreach ($Domain->host as $host) {
            if ($host['MX'] == "y") {
              $priority=$host['Priority'];
              $dns->add_MX("@", $host['name'].".".$Domain['zone'], $priority);
            }
          }
          $dns->txt_extMX();
          if ($Domain['externalMX'] != "") {
            $priority=$host['Priority'];
            $dns->add_extMX("@", $Domain['externalMX'], $priority);
          }

          $dns->txt_A();
          foreach ($Domain->host as $host) {
            $dns->add_A($host['name'], $host['IPv4']);
          }
          $dns->txt_AAAA();
          foreach ($Domain->host as $host) {
            $dns->add_AAAA($host['name'], $host['IPv6']);
          }
          $dns->txt_CNAME();
          foreach ($Domain->host as $host) {
            $dns->add_CNAME($host['CNAME'], $host['name'],/*.".".*/ $Domain['zone']);
          }
          $dns->txt_CNAMEEXT();
          foreach ($Domain->host as $host) {
            $dns->add_CNAMEEXT($host['CNAME'], $host['name'],/*.".".*/ $Domain['zone']);
          }
          $dns->txt_DELEGATIONS();
        foreach ($Domain->delegation as $host) {
          if ($host['NS']) {
            $dns->add_NS($host['name']."." , $host['NS']);
            $dns->add_A($host['NS'].".", $host['IPv4']);
          }
        }
      $dns->db_end();

      }
      foreach ($domains->slave as $Domain) {
        // check connectivity
        if ($this->checkNameserver($Domain['master'], $Domain['zone'])) {
          //add server
          $dns->zone((string)$_name,$Domain['zone'], "slave", $Domain['master'], "");
        }
      }
      foreach ($domains->forward as $Domain) {
        // check connectivity
        if ($this->checkNameserver($Domain['forwarder'], $Domain['zone'])) {
          //add server
          $dns->zone((string)$_name,$Domain['zone'], "forward", $Domain['forwarder'], "");
        }

      }
    $dns->view_end();
  }

  function named() {

    $url = $this->cnml_host."/guifi/cnml/".$this->service_id."/domains";
    $cnml = '';
    $h = fopen($url, "r") or die(date("YmdHi")." xUnable to fetch CNML.\n");
    while (!feof($h)) { $cnml .= fgets( $h ) or die(date("YmdHi")." xUnable to read CNML.\n"); }
    fclose( $h );

    $check = check_cnml( $cnml );
    if ($check = true) {
      $xml = new SimpleXMLElement( $cnml );
      $dns = new BIND($this->master_dir, $this->slave_dir, $this->chroot);
      $dns->named();
      foreach ($xml->domains as $domains) {
        foreach ($domains->internal as $zones) {
          $op = "\tmatch-clients { 127.0.0.1; 192.168.0.0/16; 10.0.0.0/8; 172.16.0.0/12; };\n\trecursion yes;\n\tinclude \"".$this->master_dir."/named.conf.int.private\";";
          $this->view("", $op, $zones, $dns);
        }
        foreach ($domains->external as $zones) {
          $op = "\tmatch-clients { any; };\n\trecursion no;\n\tinclude \"".$this->master_dir."/named.conf.ext.private\";";
          $this->view("internet", $op, $zones, $dns);
        }
      }
    }
  }


# Creates guifi's Reverse Name Resolution Zone Files (RRZ) entries
# Gets data from guifi's CNML 'ips' command
#
# joan.llopart _AT_ guifi.net  09-2008
  function rrz() {
    error_reporting( E_PARSE );
    $timestamp = time();

    # 172.x.x.x RRZ header
    $rrz172 = <<<EOF
\$TTL 86400
\$ORIGIN 172.in-addr.arpa.
@		IN	SOA	ns1.guifi.net.	nobody.guifi.net. (
				$timestamp; Serial no., based on date
				21600; Refresh after 6 hours
				3600; Retry after 1 hour
				604800; Expire after 7 days
				3600; Minimum TTL of 1 hour
		)
@		IN	NS	ns1.guifi.net.


EOF;

    # 10.x.x.x RRZ header
    $rrz10 = <<<EOF
\$TTL 86400
\$ORIGIN 10.in-addr.arpa.
@		IN	SOA	ns1.guifi.net.	nobody.guifi.net. (
				$timestamp; Serial no., based on date
				21600; Refresh after 6 hours
				3600; Retry after 1 hour
				604800; Expire after 7 days
				3600; Minimum TTL of 1 hour
		)
@		IN	NS	ns1.guifi.net.


EOF;

    # ip.guifi.net header
    $ipguifi = <<<EOF
@		IN	SOA	ns1.guifi.net.	nobody.guifi.net. (
				$timestamp; Serial no., based on date
				21600; Refresh after 6 hours
				3600; Retry after 1 hour
				604800; Expire after 7 days
				3600; Minimum TTL of 1 hour
		)
@		IN	NS	ns1.guifi.net.


EOF;

    $url = $this->cnml_host."/dump/ips";
    $cnml = '';
    $h = fopen( $url , "r") or die(date("YmdHi")." aUnable to fetch CNML.\n");
      while (!feof($h)) { $cnml .= fgets( $h ) or die(date("YmdHi")." aUnable to read CNML.\n"); }
    fclose( $h );
    $check = check_cnml( $cnml );
    if ($check = true) {
      $xml = new SimpleXMLElement( $cnml );
        foreach ($xml->subnet as $subnet) {
          $tmp='';
          foreach ($subnet->IP as $ip) {
            # Need to output octets in reverse order
            list( $ip1, $ip2, $ip3, $ip4 ) = explode( "\.", $ip['address'], 4 );
            # Only "A-Z", "a-z", "-" and "0-9" are allowed
            $nick = preg_replace( '/[^A-Za-z-0-9]/i', '', $ip['nick']  );
            switch ($subnet['range'] ) {
              case '10':
                $ipguifi .= "$nick--{$ip['device_id']}.ip.guifi.net.\tIN\tA\t$ip1.$ip2.$ip3.$ip4\n";
                break;
            }
            # Nice looking TABs
            if( strlen( "$ip4$ip3$ip2" ) < 6 ) { $ip2.="\t"; }
            $tmp .= "$ip4.$ip3.$ip2\tIN\tPTR\t$nick--{$ip['device_id']}.ip.guifi.net.\n";
          }
          switch ($subnet['range'] ) {
            case '172':
            $rrz172 .= $tmp;
            break;
            case '10':
            $rrz10 .= $tmp;
            break;
          }
       }
    }

    $fn=$this->chroot.$this->master_dir."/172.ip.guifi.net.rrz";
    $h = fopen($fn, 'w') or die(date("YmdHi")." Unable to create file ($fn), check filesystem permissions.\n");
    fwrite($h, $rrz172) or die(date("YmdHi")." Unable to write to file ($fn).\n");
    fclose($h);

    $fn=$this->chroot.$this->master_dir."/10.ip.guifi.net.rrz";
    $h = fopen($fn, 'w') or die(date("YmdHi")." Unable to create file ($fn), check filesystem permissions.\n");
    fwrite( $h, $rrz10 ) or die(date("YmdHi")." Unable to write to file ($fn).\n");
    fclose($h);

    $fn=$this->chroot.$this->master_dir."/ip.guifi.net";
    $h = fopen($fn, 'w') or die(date("YmdHi")." Unable to create file ($fn), check filesystem permissions.\n");
    fwrite( $h, $ipguifi ) or die(date("YmdHi")." Unable to write to file ($fn).\n");
    fclose($h);
  }

/*
 * Check the connectivity with the server name
 */
  function checkNameserver($server, $zone){
    // attempt to connect
    if ($x = @fsockopen($server, 53, $errno, $errstr, 7)) {
      //close connection
      if ($x) @fclose($x);
      return true;
    }
    else {
      echo "Server ". $server ." for ". $zone ." is down, skipping until the next check.\n";
      return false;
    }
  }
} // end DNSservices

  require_once("/etc/dnsservices/config.php");
  $updated = check_updated($DNSDataServer_url);
  if ($updated = true) {
    if (count($argv) == 1) {
       $secs = $DNSGraphServerId % 120;
       echo "Sleeping for ".$secs." seconds to avoid server peaks.\n";
       sleep($secs);
    }
    $gdns = new DNSservices($DNSDataServer_url, $DNSGraphServerId, $master_dir, $slave_dir, $chroot);
    $gdns->named();
    $gdns->rrz();
  }

?>
