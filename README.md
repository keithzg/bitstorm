BitStorm
========

A fork of BitStorm (a super-thin bittorrent tracker) with MySQL support that uses the PHP mysqli database API.

BitStorm was originally written by [Peter Caprioli](https://caprioli.se/) as a lightweight bittorrent tracker contained in a single PHP file.
As it used only a single flat file as a database, it had difficulty scaling past ~10 announces per second.

Peter created a fork of his project to add MySQL support, allowing it to scale.
In 2011, [Josh Duff](http://joshduff.com/) made some changes to allow more efficient use of the database, and further scaling. In 2013, [Laurent Etiemble](http://blog.laurent.etiemble.com/) modernized it a bit more, and in 2018 [Keith Zubot-Gephart](https://keithzg.ca) did some more of that after looking around for a simple bittorrent tracker to install on a modern LAMP server and finding nothing decent other than this.

BitStorm: a very light bittorrent tracker that anyone can install!
