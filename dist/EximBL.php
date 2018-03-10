<?php
namespace Coercive\Utility\MailLog;

use Exception;

/**
 * EXIM BLACK LIST : Get the rejected email list
 *
 * @package		Coercive\Utility\MailLog
 * @link		@link https://github.com/Coercive/MailLog
 *
 * @author  	Anthony Moral <contact@coercive.fr>
 * @copyright   (c) 2018 Anthony Moral
 * @license 	MIT
 */
class EximBL
{
	const REGEXP = '^(?P<datetime>[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) (?P<id>[a-zA-Z0-9-]+) (?P<status>..) (?P<email>[^ ]+@[^ ]+) .+$';

	/** @var string Log filepath */
	private $path = '';

	/** @var string Raw datas from shell */
	private $raw = '';

	/** @var array Raw datas to array */
	private $datas = [];

	/** @var array Handled datas : datetime | id | status | email */
	private $list = [];

	/** @var array Emails list (distinct) */
	private $emails = [];

	/** @var int NB emails (distinct) */
	private $nb = 0;

	/**
	 * EximBL constructor.
	 *
	 * @param string $path
	 * @throws Exception
	 */
	public function __construct(string $path = '/var/log/exim4/mainlog')
	{
		# Verify access to log file
		if(!is_file($path) && is_readable($path)) {
			throw new Exception("Can't access log file in : $path");
		}

		# Set
		$this->path = $path;

		# Launch
		$this->grep();
		$this->handle();
	}

	/**
	 * Get only the rejected emails
	 *
	 * @return void
	 */
	private function grep()
	{
		$this->raw = shell_exec('grep " \*\* .*@.*" ' . $this->path);
	}

	/**
	 * Handle log stream and convert to exploitable datas
	 *
	 * @return bool
	 */
	private function handle(): bool
	{
		# Transform shell lines to array
		$this->datas = explode(PHP_EOL, $this->raw);

		# Delete empty lines
		foreach ($this->datas as $k => $v) {

			// Empty line
			if(!trim($v)) {
				unset($this->datas[$k]);
				continue;
			}

			// Error datas format
			if(!preg_match('`' . self::REGEXP . '`', $v, $matches)) {
				unset($this->datas[$k]);
				continue;
			}

			// Full data list
			$this->list[sha1($matches['id'])] = [
				'datetime' => $matches['datetime'],
				'id' => $matches['id'],
				'status' => $matches['status'],
				'email' => $matches['email'],
			];

			// Only distinct email
			$this->emails[sha1($matches['email'])] = $matches['email'];
		}

		# Count real lines (distinct email)
		$this->nb = count($this->emails);

		# Return status
		return (bool) $this->nb;
	}

	/**
	 * Return all datas handled in id | status | datetime | email
	 *
	 * @return array
	 */
	public function get(): array
	{
		return $this->list;
	}

	/**
	 * Return only distinct emails
	 *
	 * @return array
	 */
	public function getEmails(): array
	{
		return $this->emails;
	}

	/**
	 * Return real nb lines detect
	 *
	 * @return int
	 */
	public function getNb(): int
	{
		return $this->nb;
	}
}