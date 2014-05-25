whatsapp-conversation-parser
============================

Its the PHP script to parse the whatsapp conversation text files. When you export and email your whatspp conversation they are received as the text file in an email. What if you wanted it to be exported to some kind of database or use to for some other purpose for which text file wont suffice. This script will allow you to do so.


Example to parse:
------------------
php WhatsappParser.php <file-to-parse>

Dependency
----------
- PHP
- SQLite 3

Output
------
The conversation will get stored in the SQLite 3 database which will be generated from the name of the file you provided.