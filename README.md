Extended List for DokuWiki
==========================

This plugin extends DokuWiki's list markup syntax to allow:  
 1) html5 description lists, as well as ordered/unordered lists  
 2) start any number of an ordered list and give any number for member items  
 3) break long item into multiple lines indented by at least two spaces  
 4) class attribute for lists block  

Lists can be nested within lists, just as in the standard DokuWiki syntax.


```
  -  ordered list item            (DokuWiki standard syntax)
  *  unordered list item          (DokuWiki standard syntax)

  ;  description list term, compacted/reduced column width
  ;; description list term, column width is NOT reduced
  :  description list item
```

Ordered list also starts with actual number followed with period and space (`". "`):

```
    100. start handred
    105. skip 101 to 104
```

You may write longer list items in consecutive indented lines of text:

```
    * Lorem ipsum dolor sit amet, consectetur 
      adipiscing elit, sed do eiusmod tempor 
      incididunt ut labore et dolore magna aliqua.
```


----
Licensed under the GNU Public License (GPL) version 2

(c) 2015-2016 Satoshi Sahara \<sahara.satoshi@gmail.com>
