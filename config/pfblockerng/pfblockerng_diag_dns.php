<?php
/*
	pfBlockerNG_diag_dns.php

	pfBlockerNG
	Copyright (C) 2014 BBcan177@gmail.com
	All rights reserved.

	Original Code by: 
	Copyright (C) 2009 Jim Pingle (jpingle@gmail.com)
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:


	1. Redistributions of source code must retain the above copyright notice,
	this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	notice, this list of conditions and the following disclaimer in the
	documentation and/or other materials provided with the distribution.


	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/

$pgtitle = array(gettext("pfBlockerNG"),gettext("DNS/Threat Source Lookup"));
require("guiconfig.inc");

$host = trim($_REQUEST['host'], " \t\n\r\0\x0B[];\"'");
$host_esc = escapeshellarg($host);

if (is_array($config['aliases']['alias'])) {
	$a_aliases = &$config['aliases']['alias'];
} else {
	$a_aliases = array();
}
$aliasname = str_replace(array(".","-"), "_", $host);
$alias_exists = false;
$counter=0;
foreach($a_aliases as $a) {
	if($a['name'] == $aliasname) {
		$alias_exists = true;
		$id=$counter;
	}
	$counter++;
}

# Collect pfSense Version
$pfs_version = substr(trim(file_get_contents("/etc/version")), 0, 3);

if ($pfs_version > '2.2') {
	$cmd = '/usr/bin/drill';
} else {
	$cmd = '/usr/bin/dig';
}


if(isset($_POST['create_alias']) && (is_hostname($host) || is_ipaddr($host))) {
	if($_POST['override'])
		$override = true;
	$resolved = gethostbyname($host);
	$type = "hostname";
	if($resolved) {
		$resolved = array();
		exec("{$cmd} {$host_esc} A | /usr/bin/grep {$host_esc} | /usr/bin/grep -v ';' | /usr/bin/awk '{ print $5 }'", $resolved);
		$isfirst = true;
		foreach($resolved as $re) {
			if($re <> "") {
				if(!$isfirst)
					$addresses .= " ";
				$addresses .= rtrim($re) . "/32";
				$isfirst = false;
			}
		}
		$newalias = array();
		if($override) 
			$alias_exists = false;
		if($alias_exists == false) {
			$newalias['name'] = $aliasname;
			$newalias['type'] = "network";
			$newalias['address'] = $addresses;
			$newalias['descr'] = "Created from Diagnostics-> DNS Lookup";
			if($override) 
				$a_aliases[$id] = $newalias;
			else
				$a_aliases[] = $newalias;
			write_config();
			$createdalias = true;
		}
	}
}

if ($_POST) {
	unset($input_errors);

	$reqdfields = explode(" ", "host");
	$reqdfieldsn = explode(",", "Host");

	do_input_validation($_POST, $reqdfields, $reqdfieldsn, $input_errors);
	
	if (!is_hostname($host) && !is_ipaddr($host)) {
		$input_errors[] = gettext("Host must be a valid hostname or IP address.");
	} else {
		// Test resolution speed of each DNS server.
		$dns_speeds = array();
		$dns_servers = array();
		exec("/usr/bin/grep nameserver /etc/resolv.conf | /usr/bin/cut -f2 -d' '", $dns_servers);
		foreach ($dns_servers as $dns_server) {
			$query_time = exec("{$cmd} {$host_esc} " . escapeshellarg("@" . trim($dns_server)) . " | /usr/bin/grep Query | /usr/bin/cut -d':' -f2");
			if($query_time == "")
				$query_time = gettext("No response");
			$new_qt = array();
			$new_qt['dns_server'] = $dns_server;
			$new_qt['query_time'] = $query_time;
			$dns_speeds[] = $new_qt;
			unset($new_qt);
		}
	}

	$type = "unknown";
	$resolved = "";
	$ipaddr = "";
	$hostname = "";
	if (!$input_errors) {
		if (is_ipaddr($host)) {
			$type = "ip";
			$resolved = gethostbyaddr($host);
			$ipaddr = $host;
			if ($host != $resolved)
				$hostname = $resolved;
		} elseif (is_hostname($host)) {
			$type = "hostname";
			$resolved = gethostbyname($host);
			if($resolved) {
				$resolved = array();
				exec("{$cmd} {$host_esc} A | /usr/bin/grep {$host_esc} | /usr/bin/grep -v ';' | /usr/bin/awk '{ print $5 }'", $resolved);
			}
			$hostname = $host;
			if ($host != $resolved)
				$ipaddr = $resolved[0];
		}

		if ($host == $resolved) {
			$resolved = gettext("No record found");
		}
	}
}

function display_host_results ($address,$hostname,$dns_speeds) {
	$map_lengths = function($element) { return strlen($element[0]); };

	echo gettext("IP Address") . ": {$address} \n";
	echo gettext("Host Name") . ": {$hostname} \n";
	echo "\n";
	$text_table = array();
	$text_table[] = array(gettext("Server"), gettext("Query Time"));
	if (is_array($dns_speeds)) {
		foreach ($dns_speeds as $qt) {
			$text_table[] = array(trim($qt['dns_server']), trim($qt['query_time']));
		}
	}
	$col0_padlength = max(array_map($map_lengths, $text_table)) + 4;
	foreach ($text_table as $text_row) {
		echo str_pad($text_row[0], $col0_padlength) . $text_row[1] . "\n";
	}
}

include("head.inc"); ?>
<body link="#0000CC" vlink="#0000CC" alink="#0000CC">
<?php include("fbegin.inc"); ?>
<table width="100%" border="0" cellpadding="0" cellspacing="0">
	<tr>
		<td>
<?php if ($input_errors) print_input_errors($input_errors); ?>
	<form action="/pfblockerng/pfblockerng_diag_dns.php" method="post" name="iform" id="iform">
	  <table width="100%" border="0" cellpadding="6" cellspacing="0">
		<tr>
			<td colspan="2" valign="top" class="listtopic"> <?=gettext("Resolve DNS hostname or IP");?></td>
		</tr>
	<tr>
		<td width="22%" valign="top" class="vncellreq"><?=gettext("Hostname or IP");?></td>
		<td width="78%" class="vtable">
	<?=$mandfldhtml;?>
			<table>
				<tr><td valign="top">
			<input name="host" type="text" class="formfld" id="host" size="20" value="<?=htmlspecialchars($host);?>">
			</td>
			<td>
			<?php if ($resolved && $type) { ?>
			=  <font size="+1">
<?php
				$found = 0;
				if(is_array($resolved)) { 
					foreach($resolved as $hostitem) {
						if($hostitem <> "") {
							echo $hostitem . "<br/>";
							$found++;
						}
					}
				} else {
					echo $resolved; 
				} 
				if($found > 0) { ?>
					<br/><font size='-2'>
				<?PHP	if($alias_exists) { ?>
							An alias already exists for the hostname <?= htmlspecialchars($host) ?>. <br />
							<input type="hidden" name="override" value="true"/>
							<input type="submit" name="create_alias" value="Overwrite Alias"/>
				<?PHP	} else {
						if(!$createdalias) { ?>
							<input type="submit" name="create_alias" value="Create Alias from These Entries"/>
					<?PHP	} else { ?>
							Alias created with name <?= htmlspecialchars($newalias['name']) ?>
					<?PHP	}
					}
				}
?>
				<font size="-1">

			<?	} ?>
			</td></tr></table>
		  </td>
		</tr>
<?php		if($_POST): ?>
		<tr>
		  <td width="22%" valign="top" class="vncell"><?=gettext("Resolution time per server");?></td>
		  <td width="78%" class="vtable">
				<table width="170" border="1" cellpadding="2" style="border-width: 1px 1px 1px 1px; border-collapse: collapse;">
					<tr>
						<td>
							<b><?=gettext("Server");?></b>
						</td>
						<td>
							<b><?=gettext("Query time");?></b>
						</td>
					</tr>
<?php
					if(is_array($dns_speeds))
						foreach($dns_speeds as $qt):
?>
					<tr>
						<td>
							<?=$qt['dns_server']?>
						</td>
						<td>
							<?=$qt['query_time']?>
						</td>
					</tr>
<?php
					endforeach;
?>
				</table>
		  </td>
		</tr>
		<?php endif; ?>
		<?php if (!$input_errors && $ipaddr) { ?>
		<tr>

			<td width="22%" valign="top"  class="vncell"><?=gettext("More Information:");?></td>
			<td width="78%" class="vtable">
				<a target="_new" href ="/diag_ping.php?host=<?=htmlspecialchars($host)?>&interface=wan&count=3"><?=gettext("Ping");?></a> <br/>
				<a target="_new" href ="/diag_traceroute.php?host=<?=htmlspecialchars($host)?>&ttl=18"><?=gettext("Traceroute");?></a>
				<p/>
				<?=gettext("NOTE: The following links are to external services, so their reliability cannot be guaranteed.");?><br/><br/>
				<a target="_new" href="http://private.dnsstuff.com/tools/whois.ch?ip=<?php echo $ipaddr; ?>"><?=gettext("IP WHOIS @ DNS Stuff");?></a><br />
				<a target="_new" href="http://private.dnsstuff.com/tools/ipall.ch?ip=<?php echo $ipaddr; ?>"><?=gettext("IP Info @ DNS Stuff");?></a>

				<?=gettext("NOTE: The following links are to external services, so their reliability cannot be guaranteed.");?><br/><br/>
				<a target="_new" href="http://kb.bothunter.net/ipInfo/nowait.php?IP=<?php echo $ipaddr; ?>"><?=gettext("BOTHunter");?></a><br/>
				<a target="_new" href="http://www.ipvoid.com/scan/<?php echo $ipaddr; ?>/"><?=gettext("IPVOID");?></a><br/>
				<a target="_new" href="http://www.tcpiputils.com/browse/ip-address/<?php echo $ipaddr; ?>/"><?=gettext("TCPUtils");?></a><br/>
				<a target="_new" href="https://www.herdprotect.com/ip-address-<?php echo $ipaddr; ?>.aspx"><?=gettext("Herd Protect");?></a><br/>
				<a target="_new" href="https://www.senderbase.org/lookup/ip/?search_string=<?php echo $ipaddr; ?>"><?=gettext("SenderBase");?></a><br/>
				<a target="_new" href="http://www.ip-tracker.org/locator/ip-lookup.php?ip=<?php echo $ipaddr; ?>"><?=gettext("IP Tracker");?></a><br/>

				<a target="_new" href="https://www.fortiguard.com/ip_rep/index.php?data=/<?php echo $ipaddr; ?>?"><?=gettext("FortiGuard");?></a><br/>
				<a target="_new" href="https://www.projecthoneypot.org/ip_<?php echo $ipaddr; ?>"><?=gettext("Project HoneyPot");?></a><br/>
				<a target="_new" href="https://www.virustotal.com/en/ip-address/<?php echo $ipaddr; ?>/information"><?=gettext("VirusTotal Info");?></a><br/>
				<a target="_new" href="https://www.mcafee.com/threat-intelligence/ip/default.aspx?ip=<?php echo $ipaddr; ?>"><?=gettext("McAfee Threat Center");?></a><br/>
				<a target="_new" href="http://sitecheck2.sucuri.net/results/<?php echo $ipaddr; ?>"><?=gettext("Securi SiteCheck");?></a><br/>
				<a target="_new" href="https://www.dshield.org/ipinfo.html?IP=<?php echo $ipaddr; ?>"><?=gettext("DShield Threat Lookup");?></a><br/>
				<a target="_new" href="https://isc.sans.edu/ipinfo.html?ip=<?php echo $ipaddr; ?>"><?=gettext("Internet Storm Center");?></a><br/>
				<a target="_new" href="https://www.mywot.com/en/scorecard/<?php echo $ipaddr; ?>"><?=gettext("Web of Trust (WOT) Scorecard");?></a><br/>
				<a target="_new" href="https://quttera.com/sitescan/<?php echo $ipaddr; ?>"><?=gettext("Quattera");?></a><br/>
				<a target="_new" href="https://www.iblocklist.com/search.php?string=<?php echo $ipaddr; ?>"><?=gettext("I-Block List");?></a><br/>
				<p/>
				<?=gettext("NOTE: Mail Server DNSRBL Lookups");?><br/><br/>
				<a target="_new" href="https://senderscore.org/lookup.php?lookup=<?php echo $ipaddr; ?>&ipLookup=Go"><?=gettext("SenderScore");?></a><br/>
				<a target="_new" href="http://www.spamhaus.org/query/bl?ip=<?php echo $ipaddr; ?>"><?=gettext("Spamhaus Blocklist");?></a><br/>
				<a target="_new" href="http://www.spamcop.net/w3m?action=checkblock&ip=<?php echo $ipaddr; ?>"><?=gettext("SPAMcop Blocklist");?></a><br/>
				<a target="_new" href="http://multirbl.valli.org/lookup/<?php echo $ipaddr; ?>.html"><?=gettext("multirbl RBL Lookup");?></a><br/>
				<a target="_new" href="http://mxtoolbox.com/SuperTool.aspx?action=blacklist%3a<?php echo $ipaddr; ?>&run=toolpage"><?=gettext("MXToolbox");?></a><br/>

			</td>
		</tr>
		<?php } ?>
		<tr>
		  <td width="22%" valign="top">&nbsp;</td>
		  <td width="78%">
			<br/>&nbsp;
            <input name="Submit" type="submit" class="formbtn" value="<?=gettext("DNS Lookup");?>">
		</td>
		</tr>
	</table>
</td></tr></table>
</form>
<?php include("fend.inc"); ?>