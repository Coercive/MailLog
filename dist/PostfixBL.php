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
	const DAEMON_QMGR = 'postfix/qmgr';
	const DAEMON_SMTP = 'postfix/smtp';

	const REGEXP_IDS = '^(?P<date>[a-zA-Z]+ \d+) (?P<time>[0-9]{2}:[0-9]{2}:[0-9]{2}) (?P<server>@SERVER@) (?P<daemon>@DAEMON_SMTPD@)\[\d+\]: (?P<id>[A-Z0-9]+): (?P<client>@CLIENT@).*$';
	const REGEXP_FROM = '^.+ @DAEMON_QMGR@\[\d+\]: @ID@: .*from=\<(?P<email>[^ ]+@[^ ]+)\>,.+$';
	const REGEXP_SEND = '^.+ @DAEMON_SMTP@\[\d+\]: @ID@:.* to=\<(?P<to>[^ ]+@[^ ]+)\>, (?:orig_to=\<(?P<orig_to>[^ ]+@[^ ]+)\>, )?.*status=(?P<status>[a-z]+) .*$';
	const REGEXP_END = '^.+ @DAEMON_QMGR@\[\d+\]: @ID@:.* removed$';

	const STATUS_SENT = 'sent';
	const STATUS_DEFERED = 'defered';
	const STATUS_BOUNCED = 'bounced';
	const STATUS_EXPIRED = 'expired';

	# Prepared regexp
	private $REGEXP_IDS = '';
	private $REGEXP_FROM = '';
	private $REGEXP_SEND = '';
	private $REGEXP_END = '';

	/** @var string Log filepath */
	private $path = '';

	/** @var string Target client for retrieve id list */
	private $client = '';

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
		$search = [
			'@SERVER@' => $this->server,
			'@CLIENT@' => $this->client,
			'@DAEMON_SMTPD@' => preg_quote(self::DAEMON_SMTPD, '`'),
			'@DAEMON_QMGR@' => preg_quote(self::DAEMON_QMGR, '`'),
			'@DAEMON_SMTP@' => preg_quote(self::DAEMON_SMTP, '`'),
		];
		$this->REGEXP_IDS = str_replace(array_keys($search), array_values($search), self::REGEXP_IDS);
		$this->REGEXP_FROM = str_replace(array_keys($search), array_values($search), self::REGEXP_FROM);
		$this->REGEXP_SEND = str_replace(array_keys($search), array_values($search), self::REGEXP_SEND);
		$this->REGEXP_END = str_replace(array_keys($search), array_values($search), self::REGEXP_END);
	}

	/**
	 * Prepare the IDS list
	 *
	 * @return void
	 */
	private function prepareIds()
	{
		# Grep log file
		$daemon = preg_quote(self::DAEMON_SMTPD, '`');
		$cmd = 'grep -E ".+' . $this->server . '.+' . $daemon . '.+' . $this->client . '" ' . $this->path;
		$raw = shell_exec($cmd);

		# Transform shell lines to array
		$rows = explode(PHP_EOL, $raw);

		# Process retrieving ID
		foreach ($rows as $row)
		{
			// Empty line
			if(!trim($row)) { continue; }

			// Error datas format
			if(!preg_match('`' . $this->REGEXP_IDS . '`', $row, $matches)) { continue; }

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
			if(!strpos($raw, ' status=' . self::STATUS_BOUNCED) {
				continue;
			}

			# Transform shell lines to array
			$rows = explode(PHP_EOL, $raw);

			# Save full data and init entries
			$this->list[$id]['raw'] = $raw;
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

				# Detection of real send
				$regexp = str_replace('@ID@', $id, $this->REGEXP_SEND);
				if(preg_match('`' . $regexp . '`', $row, $matches)) {

					# Add to full list
					$this->list[$id]['smtp'][] = [
						'to' => $matches['to'],
						'orig_to' => $matches['orig_to'] ?? '',
						'status' => $matches['status']
					];

					# Add to rejected list if debounce
					if($matches['status'] === self::STATUS_BOUNCED) {
						$email = $matches['orig_to'] ?? $matches['to'];
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
	 * @param string $client Target client for retrieve id
	 * @param string $server Id of the postfix server
	 * @throws Exception
	 */
	public function __construct(string $path = '/var/log/mail.log', string $client = 'localhost', string $server = '[a-z0-9]+')
	{
		# Verify access to log file
		if(!is_file($path) && is_readable($path)) {
			throw new Exception("Can't access log file in : $path");
		}

		# Set
		$this->path = $path;
		$this->client = preg_quote($client, '`');
		$this->server = $server;

		# Launch
		$this->prepareRegexp();
		$this->prepareIds();
		$this->prepareBlackList();
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
