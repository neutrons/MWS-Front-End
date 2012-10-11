This is a collection of PHP scripts that provide a front-end to the Moab Web Services API.

This is a development branch that was forked off the main branch.  It's being used as a place to check in relatively unstable changes while I make major changes to the PHP code.  Besides just interfacing with an LDAP server, we'll be adding code to handle uploading of scripts to run and doing a better job of handling output files to be downloaded.

============================================================================
                          BASIC DESIGN DOC
============================================================================

Basic Requirements:
I) Client must be able to upload script for execution on the cluster by MWS
	A) I'm assuming python scripts, but the actual type of script doesn't matter to the server.
	B) Technically, we don't have to limit it to scripts.  We could allow uploads of actual binary executables.  Practically speaking, though, creating a working binary when you don't have login access to the cluster to compile said binary is somewhat tricky. 
II) Must support "ensemble runs" - ie: multiple jobs that are all related to each other.
III) Must be able to get the job output back from from the server. (Yeah, this is obvious, but it's also non-trivial)


Basic Workflow:
1) Client opens a new transaction on the server
2) Server returns transaction ID and directory name
	A) ID and directory name must be unique
	B) Directory is location for file upload & download for this transaction
	C) It's not yet clear what actions the server will have to take when a new transaction is started, but it will at least have to create the directory and probably add a few rows to a database
3) Client uploads script to run
	A) Client only specifies the file name, not the full path.  Server will store it in the directory created for this transaction
<Repeat step 3 as necessary>
4) Client submits job to MWS
	A) Server will intercept return value from MWS and store the job ID in the database in addition to passing it back to the client
<Repeat step 4 as necessary>
<Wait for jobs to complete>
5) Client downloads output files
6) Client closes transaction
	A) On transaction close, server will delete directory and everything in it and purge transaction info from the database


Notes:
1) Server will authenticate *ALL* requests from client using existing HTTP BasicAuth and LDAP setup
2) All client requests (except for 'open transaction') must include the transaction ID
3) We'll use a database to tie everything together: transaction ID, user name, MOAB job ID's, etc...  See below.
4) Hopefully, we can keep the DB activity light enough to get away with sqlite.  Otherwise, we need something like mysql or postgreslq running in a separate process
5) We'll use JSON to return data from the server back to the client (because we're already set up to handle JSON)
6)  This is all running as the apache user, so directories and uploaded files will be owned by that user.  Moab runs jobs as the actual authenticated user, so directories will need to be globally read, execute and files will need global read perms.  Since the moab job will also write output to that directory as the authenticated user, the directory will also need global write permissions.
7) All files/directories will remain on the server until the transaction is closed.
8) Will need an API to return transaction ID's for a user
9) Will need an API to return MOAB job ID's for a transaction  (MWS itself already has an API for querying job status from a job ID)
10) 7, 8 & 9 give us the ability to close MantidPlot, and come back later (possibly from an entirely different machine) and still get our job output
11) Transactions will be tied to user names, so user X won't be able to do anything to a transaction belonging to user Y
12) The file upload & download interface will only accept the file name, not the full path.  The server will get the full path from the database using the transaction ID.
13) I mentioned this in the origin design note, but it's worth repeating:  we'll need an API for querying the function call signatures for the Mantid python functions that will execute on the cluster (because it's possible they'll be different from what the client knows about...)
14) We'll probably want some kind of admin interface that will let us close old (and presumably forgotten) transactions so their files won't clutter up the server


Database Notes:
- Items we'll want to track in the DB: transaction ID, user name, directory name, Moab job ID (or ID's if there's more than one job).
- Indexes on transaction ID and user name

