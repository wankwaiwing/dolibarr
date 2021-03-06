#!/usr/bin/env php
<?php
/*
 * Copyright (C) 2004      Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2005-2013 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */


/**
 *      \file       scripts/emailings/mailing-send.php
 *      \ingroup    mailing
 *      \brief      Script d'envoi d'un mailing prepare et valide
 */

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path=dirname(__FILE__).'/';

// Test if batch mode
if (substr($sapi_type, 0, 3) == 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
	exit(-1);
}

if (! isset($argv[1]) || ! $argv[1]) {
	print "Usage: ".$script_file." (ID_MAILING|all)\n";
	exit(-1);
}
$id=$argv[1];
if (! isset($argv[2]) || !empty($argv[2])) $login = $argv[2];
else $login = '';

require_once ($path."../../htdocs/master.inc.php");
require_once (DOL_DOCUMENT_ROOT."/core/class/CMailFile.class.php");


// Global variables
$version=DOL_VERSION;
$error=0;



/*
 * Main
 */

@set_time_limit(0);
print "***** ".$script_file." (".$version.") pid=".dol_getmypid()." *****\n";

$user = new User($db);
// for signature, we use user send as parameter
if (! empty($login)) $user->fetch('',$login);

// We get list of emailing to process
$sql = "SELECT m.rowid, m.titre, m.sujet, m.body,";
$sql.= " m.email_from, m.email_replyto, m.email_errorsto";
$sql.= " FROM ".MAIN_DB_PREFIX."mailing as m";
$sql.= " WHERE m.statut = 1";
if ($id != 'all')
{
	$sql.= " AND m.rowid= ".$id;
	$sql.= " LIMIT 1";
}

$resql=$db->query($sql);
if ($resql)
{
	$num = $db->num_rows($resql);
	$j = 0;

	if ($num)
	{
		for ($j=0; $j<$num; $j++)
		{
			$obj = $db->fetch_object($resql);

			dol_syslog("Process mailing with id ".$obj->rowid);
			print "Process mailing with id ".$obj->rowid."\n";

			$id       = $obj->rowid;
			$subject  = $obj->sujet;
			$message  = $obj->body;
			$from     = $obj->email_from;
			$replyto  = $obj->email_replyto;
			$errorsto = $obj->email_errorsto;
			// Le message est-il en html
			$msgishtml=-1;  // Unknown by default
			if (preg_match('/[\s\t]*<html>/i',$message)) $msgishtml=1;

			$nbok=0; $nbko=0;

			// On choisit les mails non deja envoyes pour ce mailing (statut=0)
			// ou envoyes en erreur (statut=-1)
			$sql2 = "SELECT mc.rowid, mc.lastname as lastname, mc.firstname as firstname, mc.email, mc.other, mc.source_url, mc.source_id, mc.source_type, mc.tag";
			$sql2.= " FROM ".MAIN_DB_PREFIX."mailing_cibles as mc";
			$sql2.= " WHERE mc.statut < 1 AND mc.fk_mailing = ".$id;

			$resql2=$db->query($sql2);
			if ($resql2)
			{
				$num2 = $db->num_rows($resql2);
				dol_syslog("Nb of targets = ".$num2, LOG_DEBUG);
				print "Nb of targets = ".$num2."\n";

				if ($num2)
				{
					$now=dol_now();

					// Positionne date debut envoi
					$sqlstartdate="UPDATE ".MAIN_DB_PREFIX."mailing SET date_envoi='".$db->idate($now)."' WHERE rowid=".$id;
					$resqlstartdate=$db->query($sqlstartdate);
					if (! $resqlstartdate)
					{
						dol_print_error($db);
						$error++;
					}

					// Look on each email and sent message
					$i = 0;
					while ($i < $num2)
					{
						$res=1;
						$now=dol_now();

						$obj2 = $db->fetch_object($resql2);

						// sendto en RFC2822
						$sendto = str_replace(',',' ',dolGetFirstLastname($obj2->firstname, $obj2->lastname) ." <".$obj2->email.">");

						// Make subtsitutions on topic and body
						$other=explode(';',$obj2->other);
						$other1=$other[0];
						$other2=$other[1];
						$other3=$other[2];
						$other4=$other[3];
						$other5=$other[4];
						// Array of possible substitutions (See also fie mailing-send.php that should manage same substitutions)
						$signature = (!empty($user->signature))?$user->signature:''; 
						
						$substitutionarray=array(
							'__ID__' => $obj->source_id,
							'__EMAIL__' => $obj->email,
							'__LASTNAME__' => $obj2->lastname,
							'__FIRSTNAME__' => $obj2->firstname,
							'__MAILTOEMAIL__' => '<a href="mailto:'.$obj2->email.'">'.$obj2->email.'</a>',
							'__OTHER1__' => $other1,
							'__OTHER2__' => $other2,
							'__OTHER3__' => $other3,
							'__OTHER4__' => $other4,
							'__OTHER5__' => $other5,
							'__SIGNATURE__' => $signature,	// Signature is empty when ran from command line or taken from user in parameter)
							'__CHECK_READ__' => '<img src="'.DOL_MAIN_URL_ROOT.'/public/emailing/mailing-read.php?tag='.$obj2->tag.'&securitykey='.urlencode($conf->global->MAILING_EMAIL_UNSUBSCRIBE_KEY).'" width="1" height="1" style="width:1px;height:1px" border="0"/>',
							'__UNSUBSCRIBE__' => '<a href="'.DOL_MAIN_URL_ROOT.'/public/emailing/mailing-unsubscribe.php?tag='.$obj2->tag.'&unsuscrib=1&securitykey='.urlencode($conf->global->MAILING_EMAIL_UNSUBSCRIBE_KEY).'" target="_blank">'.$langs->trans("MailUnsubcribe").'</a>'
						);
						if (! empty($conf->paypal->enabled) && ! empty($conf->global->PAYPAL_SECURITY_TOKEN))
						{
							$substitutionarray['__SECUREKEYPAYPAL__']=dol_hash($conf->global->PAYPAL_SECURITY_TOKEN, 2);
							if (empty($conf->global->PAYPAL_SECURITY_TOKEN_UNIQUE)) $substitutionarray['__SECUREKEYPAYPAL_MEMBER__']=dol_hash($conf->global->PAYPAL_SECURITY_TOKEN, 2);
							else $substitutionarray['__SECUREKEYPAYPAL_MEMBER__']=dol_hash($conf->global->PAYPAL_SECURITY_TOKEN . 'membersubscription' . $obj->source_id, 2);
						}

						complete_substitutions_array($substitutionarray,$langs);
						$newsubject=make_substitutions($subject,$substitutionarray);
						$newmessage=make_substitutions($message,$substitutionarray);

						$substitutionisok=true;

						// Fabrication du mail
						$mail = new CMailFile(
						    $newsubject,
						    $sendto,
						    $from,
						    $newmessage,
						    array(),
						    array(),
						    array(),
						    '',
						    '',
						    0,
						    $msgishtml,
						    $errorsto
						);

						if ($mail->error)
						{
							$res=0;
						}
						if (! $substitutionisok)
						{
							$mail->error='Some substitution failed';
							$res=0;
						}

						// Send Email
						if ($res)
						{
							$res=$mail->sendfile();
						}

						if ($res)
						{
							// Mail successful
							$nbok++;

							dol_syslog("ok for emailing id ".$id." #".$i.($mail->error?' - '.$mail->error:''), LOG_DEBUG);

							$sqlok ="UPDATE ".MAIN_DB_PREFIX."mailing_cibles";
							$sqlok.=" SET statut=1, date_envoi='".$db->idate($now)."' WHERE rowid=".$obj2->rowid;
							$resqlok=$db->query($sqlok);
							if (! $resqlok)
							{
								dol_print_error($db);
								$error++;
							}
							else
							{
								//if cheack read is use then update prospect contact status
								if (strpos($message, '__CHECK_READ__') !== false)
								{
									//Update status communication of thirdparty prospect
									$sqlx = "UPDATE ".MAIN_DB_PREFIX."societe SET fk_stcomm=2 WHERE rowid IN (SELECT source_id FROM ".MAIN_DB_PREFIX."mailing_cibles WHERE rowid=".$obj2->rowid.")";
									dol_syslog("card.php: set prospect thirdparty status", LOG_DEBUG);
									$resqlx=$db->query($sqlx);
									if (! $resqlx)
									{
										dol_print_error($db);
										$error++;
									}

					    			//Update status communication of contact prospect
									$sqlx = "UPDATE ".MAIN_DB_PREFIX."societe SET fk_stcomm=2 WHERE rowid IN (SELECT sc.fk_soc FROM ".MAIN_DB_PREFIX."socpeople AS sc INNER JOIN ".MAIN_DB_PREFIX."mailing_cibles AS mc ON mc.rowid=".$obj2->rowid." AND mc.source_type = 'contact' AND mc.source_id = sc.rowid)";
									dol_syslog("card.php: set prospect contact status", LOG_DEBUG);

									$resqlx=$db->query($sqlx);
									if (! $resqlx)
									{
										dol_print_error($db);
										$error++;
									}
								}
                                
                                if (!empty($conf->global->MAILING_DELAY)) {
                                    sleep($conf->global->MAILING_DELAY);
                                }

							}
						}
						else
						{
							// Mail failed
							$nbko++;

							dol_syslog("error for emailing id ".$id." #".$i.($mail->error?' - '.$mail->error:''), LOG_DEBUG);

							$sqlerror="UPDATE ".MAIN_DB_PREFIX."mailing_cibles";
							$sqlerror.=" SET statut=-1, date_envoi=".$db->idate($now)." WHERE rowid=".$obj2->rowid;
							$resqlerror=$db->query($sqlerror);
							if (! $resqlerror)
							{
								dol_print_error($db);
								$error++;
							}
						}

						$i++;
					}
				}
				else
				{
					$mesg="Emailing id ".$id." has no recipient to target";
					print $mesg."\n";
					dol_syslog($mesg,LOG_ERR);
				}

				// Loop finished, set global statut of mail
				$statut=2;
				if (! $nbko) $statut=3;

				$sqlenddate="UPDATE ".MAIN_DB_PREFIX."mailing SET statut=".$statut." WHERE rowid=".$id;

				dol_syslog("update global status", LOG_DEBUG);
				print "Update status of emailing id ".$id." to ".$statut."\n";
				$resqlenddate=$db->query($sqlenddate);
				if (! $resqlenddate)
				{
					dol_print_error($db);
					$error++;
				}
			}
			else
			{
				dol_print_error($db);
				$error++;
			}
		}
	}
	else
	{
		$mesg="No validated emailing id to send found.";
		print $mesg."\n";
		dol_syslog($mesg,LOG_ERR);
		$error++;
	}
}
else
{
	dol_print_error($db);
	$error++;
}


exit($error);
