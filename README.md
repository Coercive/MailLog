# MailLog

```php
$exim = new EximBL('/var/log/exim_log_file');

echo "<pre>";
var_dump($exim->get());
var_dump($exim->getEmails());
var_dump($exim->getNb());
echo "</pre>";
```
