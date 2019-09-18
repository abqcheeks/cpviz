#
# Makefile for packing up the cpviz module
#
# $Id: Makefile,v 1.99 2017/12/15 01:44:52 cheeks Exp $
#


TARBALL    = /users/cheeks/tmp/thismonth/freepbx/cpviz.tgz

pack: $(TARBALL)

$(TARBALL): assets/css/cpviz.css functions.inc.php page.cpviz.php
	./fixver module.xml
	(cd .. ; tar cvzf cpviz.tar.gz --exclude=.git cpviz)


