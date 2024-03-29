TAIFEX Report by php
====================

Taiwan Futures Exchange difference report (臺灣期貨交易所差異報表)

[![Latest Stable Version](https://poser.pugx.org/yidas/taifex-report/v/stable?format=flat-square)](https://packagist.org/packages/yidas/taifex-report)
[![License](https://poser.pugx.org/yidas/taifex-report/license?format=flat-square)](https://packagist.org/packages/yidas/taifex-report)

OUTLINE
-------

- [Demonstration](#demonstration)
- [Requirements](#requirements)
- [Installation](#installation)
- [References](#references)

---

DEMONSTRATION
-------------

Demo Site: https://taifex-report.yidas.com/

Crawl and calculate the difference report between the current day and the previous day's Major Institutional Traders futures data from TAIFEX by accessing the website.

---

REQUIREMENTS
------------
This library requires the following:

- PHP 5.4.0+\|7.0+
- [yidas/tw-stock-crawler](https://github.com/yidas/tw-stock-crawler-php)

---

INSTALLATION
------------

Run Composer to create the project:

    composer create-project yidas/taifex-report
    
Add the write permission to the following directories:

```
/cache
```

---

REFERENCES
----------

- [GitHub - yidas/tw-stock-crawler-php](https://github.com/yidas/tw-stock-crawler-php)

- [Taiwan Futures Exchange](https://www.taifex.com.tw/enl/eIndex)



