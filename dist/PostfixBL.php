<?php
namespace Coercive\Utility\MailLog;

use Exception;

/**
 * POSTFIX BLACK LIST : Get the rejected email list
 *
 * @package		Coercive\Utility\MailLog
 * @link		@link https://github.com/Coercive/MailLog
 *
 * @author  	Anthony Moral <contact@coercive.fr>
 * @copyright   (c) 2018 Anthony Moral
 * @license 	MIT
 */
class PostfixBL
{
	const DAEMON_SMTPD = 'postfix/smtpd';
	const DAEMON_PICKUP = 'postfix/pickup';
	const DAEMON_OPENDKIM = 'opendkim';
	const DAEMON_BOUNCE = 'postfix/bounce';
	const DAEMON_CLEANUP = 'postfix/cleanup';
	const DAEMON_QMGR = 'postfix/qmgr';
	const DAEMON_SMTP = 'postfix/smtp';

	const REGEXP_IDS_SMTPD = '^(?:[a-zA-Z]+ \d+) (?:[0-9]{2}:[0-9]{2}:[0-9]{2}) (?:@SERVER@) (?:@DAEMON_SMTPD@)\[\d+\]: (?P<id>[A-Z0-9]+): client=(?:@SEARCH@).*$';
	const REGEXP_IDS_PICKUP = '^(?:[a-zA-Z]+ \d+) (?:[0-9]{2}:[0-9]{2}:[0-9]{2}) (?:@SERVER@) (?:@DAEMON_PICKUP@)\[\d+\]: (?P<id>[A-Z0-9]+): .*from=\<(?:@SEARCH@)\>.*$';
	const REGEXP_IDS_CLEANUP = '^(?:[a-zA-Z]+ \d+) (?:[0-9]{2}:[0-9]{2}:[0-9]{2}) (?:@SERVER@) (?:@DAEMON_CLEANUP@)\[\d+\]: (?P<id>[A-Z0-9]+): message-id=\<[^ ]+@(?:@SEARCH@)\>.*$';
	const REGEXP_IDS_OPENDKIM = '^(?:[a-zA-Z]+ \d+) (?:[0-9]{2}:[0-9]{2}:[0-9]{2}) (?:@SERVER@) (?:@DAEMON_OPENDKIM@)\[\d+\]: (?P<id>[A-Z0-9]+): .*\(.*(?:@SEARCH@).*\).*$';
	const REGEXP_IDS_QMGR = '^(?:[a-zA-Z]+ \d+) (?:[0-9]{2}:[0-9]{2}:[0-9]{2}) (?:@SERVER@) (?:@DAEMON_QMGR@)\[\d+\]: (?P<id>[A-Z0-9]+): .*from=\<(?:@SEARCH@)\>.*$';

	const REGEXP_SOURCE = '^.+ @DAEMON_CLEANUP@\[\d+\]: @ID@: .*message-id=\<[^ ]+@(?P<source>[^ ]+)\>.*$';
	const REGEXP_FROM = '^.+ @DAEMON_QMGR@\[\d+\]: @ID@: .*from=\<(?P<email>[^ ]+@[^ ]+)\>,.+$';
	const REGEXP_SEND = '^.+ @DAEMON_SMTP@\[\d+\]: @ID@:.* to=\<(?P<to>[^ ]+@[^ ]+)\>, (?:orig_to=\<(?P<orig_to>[^ ]+@[^ ]+)\>, )?.*status=(?P<status>[a-z]+) .*$';
	const REGEXP_END = '^.+ @DAEMON_QMGR@\[\d+\]: @ID@:.* removed$';

	const STATUS_SENT = 'sent';
	const STATUS_DEFERED = 'defered';
	const STATUS_BOUNCED = 'bounced';
	const STATUS_EXPIRED = 'expired';

	# Prepared ids regexp
	private $REGEXP_IDS = '';
	private $REGEXP_IDS_SMTPD = '';
	private $REGEXP_IDS_PICKUP = '';
	private $REGEXP_IDS_CLEANUP = '';
	private $REGEXP_IDS_OPENDKIM = '';
	private $REGEXP_IDS_QMGR = '';

	# Prepared content regexp
	private $REGEXP_SOURCE = '';
	private $REGEXP_FROM = '';
	private $REGEXP_SEND = '';
	private $REGEXP_END = '';

	/** @var string Log filepath */
	private $path = '';

	/** @var string Target daemon for retrieve id list */
	private $daemon = '';

	/** @var string Target search for retrieve id list */
	private $search = '';

	/** @var string Id of the postfix server */
	private $server = '';

	/** @var array ID list from */
	private $ids = [];

	/** @var array All item list */
	private $list = [];

	/** @var array Rejected list */
	private $rejected = [];

	/**
	 * Replace basics @ TAGS @ in regexp
	 *
	 * @return void
	 */
	private function prepareRegexp()
	{
		# PLACEHOLDER
		$search = [
			'@SERVER@' => $this->server,
			'@DAEMON_SMTPD@' => preg_quote(self::DAEMON_SMTPD, '`'),
			'@DAEMON_PICKUP@' => preg_quote(self::DAEMON_PICKUP, '`'),
			'@DAEMON_CLEANUP@' => preg_quote(self::DAEMON_CLEANUP, '`'),
			'@DAEMON_OPENDKIM@' => preg_quote(self::DAEMON_OPENDKIM, '`'),
			'@DAEMON_QMGR@' => preg_quote(self::DAEMON_QMGR, '`'),
			'@DAEMON_SMTP@' => preg_quote(self::DAEMON_SMTP, '`'),
		];

		# IDS
		$this->REGEXP_IDS_SMTPD = str_replace(array_keys($search), array_values($search), self::REGEXP_IDS_SMTPD);
		$this->REGEXP_IDS_PICKUP = str_replace(array_keys($search), array_values($search), self::REGEXP_IDS_PICKUP);
		$this->REGEXP_IDS_CLEANUP = str_replace(array_keys($search), array_values($search), self::REGEXP_IDS_CLEANUP);
		$this->REGEXP_IDS_OPENDKIM = str_replace(array_keys($search), array_values($search), self::REGEXP_IDS_OPENDKIM);
		$this->REGEXP_IDS_QMGR = str_replace(array_keys($search), array_values($search), self::REGEXP_IDS_QMGR);

		# CONTENT
		$this->REGEXP_SOURCE = str_replace(array_keys($search), array_values($search), self::REGEXP_SOURCE);
		$this->REGEXP_FROM = str_replace(array_keys($search), array_values($search), self::REGEXP_FROM);
		$this->REGEXP_SEND = str_replace(array_keys($search), array_values($search), self::REGEXP_SEND);
		$this->REGEXP_END = str_replace(array_keys($search), array_values($search), self::REGEXP_END);
	}

	/**
	 * Prepare the IDS list
	 *
	 * @param int $offset [optional]
	 * @param int $limit [optional]
	 * @return void
	 */
	private function prepareIds(int $offset = 0, int $limit = 0)
	{
		# Grep log file
		$cmd = 'grep -E ".+' . $this->server . '.+' . $this->daemon . '\[[0-9]+\]:.+[A-Z0-9]+:.+' . $this->search . '" ' . $this->path;
		$raw = shell_exec($cmd);

		# Transform shell lines to array
		$rows = explode(PHP_EOL, $raw);

		# Process retrieving ID
		$current = -1;
		foreach ($rows as $row)
		{
			// Empty line
			if(!trim($row)) { continue; }

			// Error datas format
			if(!preg_match('`' . $this->REGEXP_IDS . '`', $row, $matches)) { continue; }

			// Offset
			$current++;
			if($current < $offset) { continue; }
			if($limit && $current >= $limit) { break; }

			// Full data list
			$this->ids[$matches['id']] = $matches['id'];
		}
	}

	/**
	 * Handle log stream by ID and convert to exploitable datas
	 *
	 * @return void
	 */
	private function prepareBlackList()
	{
		# No datas
		if(!$this->ids) { return; }

		# Process all ID and detect bad status
		foreach ($this->ids as $id)
		{
			# Grep log file
			$cmd = 'grep -E ".+' . $this->server . '.+ ' . $id . ': .+" ' . $this->path;
			$raw = shell_exec($cmd);

			# Verify if status=bounce is present
			if(!strpos($raw, ' status=' . self::STATUS_BOUNCED)) {
				continue;
			}

			# Transform shell lines to array
			$rows = explode(PHP_EOL, $raw);

			# Save full data and init entries
			$this->list[$id]['raw'] = $raw;
			$this->list[$id]['source'] = '';
			$this->list[$id]['from'] = '';
			$this->list[$id]['smtp'] = [];
			$this->list[$id]['end'] = false;

			# Delete empty lines
			foreach ($rows as $row)
			{
				// Empty line
				if(!trim($row)) { continue; }

				# Detection of queue from entry
				$regexp = str_replace('@ID@', $id, $this->REGEXP_FROM);
				if(preg_match('`' . $regexp . '`', $row, $matches)) {
					$this->list[$id]['from'] = $matches['email'];
				}

				# Detection of source
				$regexp = str_replace('@ID@', $id, $this->REGEXP_SOURCE);
				if(preg_match('`' . $regexp . '`', $row, $matches)) {
					$this->list[$id]['source'] = $matches['source'];
				}

				# Detection of real send
				$regexp = str_replace('@ID@', $id, $this->REGEXP_SEND);
				if(preg_match('`' . $regexp . '`', $row, $matches)) {

					# Add to full list
					$this->list[$id]['smtp'][] = [
						'to' => $matches['to'],
						'orig_to' => $matches['orig_to'],
						'status' => $matches['status']
					];

					# Add to rejected list if debounce
					if($matches['status'] === self::STATUS_BOUNCED) {
						$email = $matches['orig_to'] ?: $matches['to'];
						$this->rejected[$id] = $email;
					}

				}

				# Detection end queue remove
				$regexp = str_replace('@ID@', $id, $this->REGEXP_END);
				if(preg_match('`' . $regexp . '`', $row)) {
					$this->list[$id]['end'] = true;
				}
			}
		}
	}

	/**
	 * PostfixBL constructor.
	 *
	 * @param string $path
	 * @param string $server Id of the postfix server
	 * @throws Exception
	 */
	public function __construct(string $path = '/var/log/mail.log', string $server = '[a-z0-9]+')
	{
		# Verify access to log file
		if(!is_file($path) && is_readable($path)) {
			throw new Exception("Can't access log file in : $path");
		}

		# Set
		$this->path = $path;
		$this->server = $server;

		# Prepare
		$this->prepareRegexp();
	}

	/**
	 * Set client for id detection
	 *
	 * @param string $search Target client for retrieve id | use preg_quote if not a regexp !
	 * @param string $daemon Target daemon witch use this client info, ex : postfix/pickup[00000]: 00000000000: uid=0 from=<example@domain.email>
	 * @return $this
	 */
	public function search(string $search = 'localhost', string $daemon = self::DAEMON_SMTPD): PostfixBL
	{
		# Set client
		$this->search = $search;

		# Set Regexp
		switch ($daemon) {

			case self::DAEMON_SMTPD:
				$this->daemon = preg_quote(self::DAEMON_SMTPD, '`');
				$this->REGEXP_IDS = $this->REGEXP_IDS_SMTPD;
				break;

			case self::DAEMON_PICKUP:
				$this->daemon = preg_quote(self::DAEMON_PICKUP, '`');
				$this->REGEXP_IDS = $this->REGEXP_IDS_PICKUP;
				break;

			case self::DAEMON_CLEANUP:
				$this->daemon = preg_quote(self::DAEMON_CLEANUP, '`');
				$this->REGEXP_IDS = $this->REGEXP_IDS_CLEANUP;
				break;

			case self::DAEMON_OPENDKIM:
				$this->daemon = preg_quote(self::DAEMON_OPENDKIM, '`');
				$this->REGEXP_IDS = $this->REGEXP_IDS_OPENDKIM;
				break;

			case self::DAEMON_QMGR:
				$this->daemon = preg_quote(self::DAEMON_QMGR, '`');
				$this->REGEXP_IDS = $this->REGEXP_IDS_QMGR;
				break;

			default:
				throw new Exception('Unhandled daemon : ' . $daemon . '. (Must be one of : ' . implode(', ', [self::DAEMON_SMTPD, self::DAEMON_PICKUP, self::DAEMON_CLEANUP, self::DAEMON_OPENDKIM, self::DAEMON_QMGR]) . ')');
		}

		# Insert client search
		$this->REGEXP_IDS = str_replace('@SEARCH@', $this->search, $this->REGEXP_IDS);

		# Maintain chainability
		return $this;
	}

	/**
	 * Launch datas parsing
	 *
	 * @param bool $blacklist [optional]
	 * @param int $offset [optional]
	 * @param int $limit [optional]
	 * @return $this
	 */
	public function parse(bool $blacklist = false, int $offset = 0, int $limit = 0): PostfixBL
	{
		# Autosearch basic smtpd
		if(!$this->daemon) { $this->search(); }

		# Parse ids
		$this->prepareIds($offset, $limit);

		# Parse for blacklist
		if($blacklist) { $this->prepareBlackList(); }

		# Maintain chainability
		return $this;
	}

	/**
	 * Return ids list
	 *
	 * @return array
	 */
	public function getIds(): array
	{
		return $this->ids;
	}

	/**
	 * Return full list
	 *
	 * @return array
	 */
	public function getFullDatas(): array
	{
		return $this->list;
	}

	/**
	 * Return rejected list
	 *
	 * @param bool $distinct Only unique email
	 * @return array
	 */
	public function getRejectedEmails(bool $distinct = false): array
	{
		return $distinct ? array_unique($this->rejected) : $this->rejected;
	}
}
