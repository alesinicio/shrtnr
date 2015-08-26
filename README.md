# shrtnr
Simple PHP URL shortener that uses two single files to achieve all functionality

The shrtnr project provides a very simple way for people to build their own URL shorteners using just two files (plus a .htaccess to provide REST functionality).
The idea is to get long and complex URLs and turn them into small addresses that can be easily distributed.

<h2>Shortened links types</h2>
Shrtnr allows you to use automatically generated sequential URL for each link that is shortened or to create your own customized links.

<h2>No interface</h2>
Shrtnr does not have any graphical interface. It uses simples API calls to insert, remove and access links.

<h2>MySQL database</h2>
Shrtnr uses a MySQL database to store the links. You should have access to one in order to make this work.

<h2>Functionalities</h2>
Shrtnr allows some configurations:
<ul>
<li>Optional password protection for inserting links</li>
<li>Optional password protection for removing links</li>
<li>Shrtnr automatically forbids two equal custom URLs</li>
<li>Configurable length and allowed characters for new automatically shortened links</li>
<li>Global "toggle switch" for completely disallowing link removal</li>
<li>Configurable "error page" if user tries to access an invalid link</li>
</ul>

<h2>Installation</h2>
<ul>
<li>Consider shrtnd should be deployed to "http://yourdomain.com".</li>
<li>Change the "config.php" file to match your settings. All settings are explained within the file.</li>
<li>Copy the two ".php" files to your root domain folder.</li>
<li>Copy the .htaccess to your server according to our example, or change yours to match our example</li>
IF YOU HAVE MORE STUFF IN THE FOLDER WHERE YOU ARE COPYING THE .htaccess FILE, BE CAREFUL!
<li>Access "http://yourdomain.com/&install" and shrtnr will create the tables needed on the database. You should have the database already created, though.</li>
</ul>

<h2>API calls</h2>
To include a new link with automatic shortened link generation, just use the following URL:<br>
<code>http://yourdomain.com/?i&url=http://www.yourlink.com.br</code>

To include a new link with your custom shortened URL, just use the following URL:<br>
<code>http://yourdomain.com/?i&url=http://www.yourlink.com.br&customURL=coolURL</code>

To remove an existing link from the database, just use the following URL:<br>
<code>http://yourdomain.com/?remove&link=notsocoolURL</code>

Shrtnr will always respond with a JSON string. The first field will always be "status", and should be 0 (error) or 1 (success).
If an error occurs, shrtnr will output a second field "error" telling you why things went wrong.
If you insert a link successfully, shtrnr returns a field "shrtnURL" with the shortened URL (either the automatically generated URL or your custom one).

<h2>Password protection</h2>
Shrtnr allows you to protect the creation and/or deletion of links. When this option is toggled on, a dynamic hashshould be included in every request.
The hash is made of a salt (wich is provided in the "config.php" file) and some information of the request itself. The calculation is made this way:

<code>hash = md5 ( salt + url + link + customURL )</code>

The parameters "url", "link" and "customURL" may be empty depending on your request type. No problem.
With the hash in hands, just append a "pwd=hashyoucalculated" parameter in your call. For example (the hash below is valid, if you are wondering):<br>
<code>http://yourdomain.com/?i&url=http://mycoolwebsite.com&customURL=mcweb&pwd=04043abcaa02d1e064afa288ff5356ee</code>

<h2>GET or POST</h2>
Shrtnr works with GET or POST calls for inserting and removing links, your choice. Just configure it in the "config.php" file. Of course, if you choose to use POST, there's no need to include all that ugly data in the URL, just pass the arguments over POST.

<h2>Feel free to change</h2>
If you like shrtnr but thinks it could be better, or want to change it, no problem. Fork the project, branch it away! If possible, leave a note. If it is something cool, I'd like to know and use it too!
