# MailLog

Rejected email detection for Exim4 and Postfix

Exim4
-----
```php
$exim = new EximBL('/var/log/exim_log_file');

echo "<pre>";
var_dump($exim->get());
var_dump($exim->getEmails());
var_dump($exim->getNb());
echo "</pre>";
```

Postfix
-------
```php
# Example of config
$postfix = new PostfixBL(
	'/var/log/mail.log',
	'localhost.localdomain[127.0.0.1]',
	'name0123'
);

# Set your search client
$postfix->search('no-reply\@my-domain\.email', PostfixBL::DAEMON_PICKUP);

# Parse options
$postfix->parse(true, 0, 10);

echo "<pre>";
var_dump($postfix->getIds());
var_dump($postfix->getFullDatas());
var_dump($postfix->getRejectedEmails());

# Distinct emails
var_dump($postfix->getRejectedEmails(true));
echo "</pre>";
```
