CKEDITOR.plugins.add("syntaxhighlight",{requires:["dialog"],lang:["en"],init:function(e){var d="syntaxhighlight";var f=e.addCommand(d,new CKEDITOR.dialogCommand(d));f.modes={wysiwyg:1,source:1};f.canUndo=false;e.ui.addButton("Code",{label:e.lang.syntaxhighlight.title,command:d,icon:this.path+"images/syntaxhighlight.gif"});CKEDITOR.dialog.add(d,this.path+"dialogs/syntaxhighlight.js")}});