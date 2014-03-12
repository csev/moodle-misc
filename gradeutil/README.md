Some scripts I used to fix grades in Moodle.  

Warning these are *HACKS* and you should review every single line before
running them.   Do not complain if they mess up your data.

I am not even going to write documentation - that is how crappy they are.

I am not a Moodle data model expert - I wrote these and they worked for
me.   I do not know what versions of Moodle they work for.  I was using 2.5.

If you are smarter than me and want to fix them / use them - feel free.
Do not complain if they mess up your data.

The fixtotal.php can be run without the fixgrade.php - the fixtotal.php
is actually much simpler and cleaner and safer :)

The Problem
-----------

I don't know how I exactly I did this - but I ended up doing something
with my LTI activities that stranded a bunch of grades in the 

    grade_grades_history

table.   I had items with identical names but somehow they ended up with
different item ids.

Both of these PHP files are intended to be copied right into your main
moodle folder right next to config.php.

Reclaiming the Grades from History with the same Item Name
----------------------------------------------------------

The basic idea of the fixgrade.php file is to find all the users in 
a class, and then find all the active item id's and then loop 
through all combinations.   Look for orphaned grades in the history table
when there is a match of item name.

If there is a max grade from the orphaned grades and there is no 
active grade for the item, we INSERT a grade for the item by copying 
and adjusting the history row and inserting it into the active grades 
table.

TODO: I did not deal with the case where there was a grade in the active
grades table but it was too low - the code just dies if it sees this.
It would be pretty easy to craft an UPDATE statement (actually much easier
than the INSERT) to change the data - but since I was not facing that
problem - I did not write code I did not test.

Now because we manually inserted these grades - the course total is
wrong - but we fix that below in fixtotal.php

Recomputing the Overall Class Grade
-----------------------------------

This is actually mildly useful on its own without fixgrade.php.  It just
cruises though looking at active grades for all users in a class and
computing the right course total and updating the course total if
it is wrong.

-- Chuck
Tue Mar 11 23:42:23 EDT 2014

