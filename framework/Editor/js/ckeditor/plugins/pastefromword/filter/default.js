(function(){var N=CKEDITOR.htmlParser.fragment.prototype,M=CKEDITOR.htmlParser.element.prototype;N.onlyChild=M.onlyChild=function(){var c=this.children,b=c.length,a=b==1&&c[0];return a||null};M.removeAnyChildWithName=function(d){var c=this.children,b=[],a;for(var e=0;e<c.length;e++){a=c[e];if(!a.name){continue}if(a.name==d){b.push(a);c.splice(e--,1)}b=b.concat(a.removeAnyChildWithName(d))}return b};M.getAncestor=function(b){var a=this.parent;while(a&&!(a.name&&a.name.match(b))){a=a.parent}return a};N.firstChild=M.firstChild=function(c){var b;for(var a=0;a<this.children.length;a++){b=this.children[a];if(c(b)){return b}else{if(b.name){b=b.firstChild(c);if(b){return b}}}}return null};M.addStyle=function(e,d,c){var b=this;var a,g="";if(typeof d=="string"){g+=e+":"+d+";"}else{if(typeof e=="object"){for(var f in e){if(e.hasOwnProperty(f)){g+=f+":"+e[f]+";"}}}else{g+=e}c=d}if(!b.attributes){b.attributes={}}a=b.attributes.style||"";a=(c?[g,a]:[a,g]).join(";");b.attributes.style=a.replace(/^;|;(?=;)/,"")};CKEDITOR.dtd.parentOf=function(c){var b={};for(var a in this){if(a.indexOf("$")==-1&&this[a][c]){b[a]=1}}return b};function L(j){var i=j.children,h,g,f=j.children.length,e,d,c=/list-style-type:(.*?)(?:;|$)/,b=CKEDITOR.plugins.pastefromword.filters.stylesFilter;g=j.attributes;if(c.exec(g.style)){return}for(var a=0;a<f;a++){h=i[a];if(h.attributes.value&&Number(h.attributes.value)==a+1){delete h.attributes.value}e=c.exec(h.attributes.style);if(e){if(e[1]==d||!d){d=e[1]}else{d=null;break}}}if(d){for(a=0;a<f;a++){g=i[a].attributes;g.style&&(g.style=b([["list-style-type"]])(g.style)||"")}j.addStyle("list-style-type",d)}}var K=/^([.\d]*)+(em|ex|px|gd|rem|vw|vh|vm|ch|mm|cm|in|pt|pc|deg|rad|ms|s|hz|khz){1}?/i,J=/^(?:\b0[^\s]*\s*){1,4}$/,I="^m{0,4}(cm|cd|d?c{0,3})(xc|xl|l?x{0,3})(ix|iv|v?i{0,3})$",H=new RegExp(I),G=new RegExp(I.toUpperCase()),F={decimal:/\d+/,"lower-roman":H,"upper-roman":G,"lower-alpha":/^[a-z]+$/,"upper-alpha":/^[A-Z]+$/},E={disc:/[l\u00B7\u2002]/,circle:/[\u006F\u00D8]/,square:/[\u006E\u25C6]/},D={ol:F,ul:E},C=[[1000,"M"],[900,"CM"],[500,"D"],[400,"CD"],[100,"C"],[90,"XC"],[50,"L"],[40,"XL"],[10,"X"],[9,"IX"],[5,"V"],[4,"IV"],[1,"I"]],B="ABCDEFGHIJKLMNOPQRSTUVWXYZ";function A(d){d=d.toUpperCase();var c=C.length,b=0;for(var a=0;a<c;++a){for(var f=C[a],e=f[1].length;d.substr(0,e)==f[1];d=d.substr(e)){b+=f[0]}}return b}function z(d){d=d.toUpperCase();var c=B.length,b=1;for(var a=1;d.length>0;a*=c){b+=B.indexOf(d.charAt(d.length-1))*a;d=d.substr(0,d.length-1)}return b}var y=0,x=null,w,v=CKEDITOR.plugins.pastefromword={utils:{createListBulletMarker:function(c,b){var a=new CKEDITOR.htmlParser.element("cke:listbullet");a.attributes={"cke:listsymbol":c[0]};a.add(new CKEDITOR.htmlParser.text(b));return a},isListBulletIndicator:function(b){var a=b.attributes&&b.attributes.style;if(/mso-list\s*:\s*Ignore/i.test(a)){return true}},isContainingOnlySpaces:function(b){var a;return(a=b.onlyChild())&&/^(:?\s|&nbsp;)+$/.test(a.value)},resolveList:function(c){var b=c.attributes,a;if((a=c.removeAnyChildWithName("cke:listbullet"))&&a.length&&(a=a[0])){c.name="cke:li";if(b.style){b.style=v.filters.stylesFilter([["text-indent"],["line-height"],[/^margin(:?-left)?$/,null,function(d){var e=d.split(" ");d=CKEDITOR.tools.convertToPx(e[3]||e[1]||e[0]);if(!y&&x!==null&&d>x){y=d-x}x=d;b["cke:indent"]=y&&Math.ceil(d/y)+1||1}],[/^mso-list$/,null,function(d){d=d.split(" ");var f=Number(d[0].match(/\d+/)),e=Number(d[1].match(/\d+/));if(e==1){f!==w&&(b["cke:reset"]=1);w=f}b["cke:indent"]=e}]])(b.style,c)||""}if(!b["cke:indent"]){x=0;b["cke:indent"]=1}CKEDITOR.tools.extend(b,a.attributes);return true}else{w=x=y=null}return false},getStyleComponents:(function(){var a=CKEDITOR.dom.element.createFromHtml('<div style="position:absolute;left:-9999px;top:-9999px;"></div>',CKEDITOR.document);CKEDITOR.document.getBody().append(a);return function(e,d,c){a.setStyle(e,d);var g={},f=c.length;for(var b=0;b<f;b++){g[c[b]]=a.getStyle(c[b])}return g}})(),listDtdParents:CKEDITOR.dtd.parentOf("ol")},filters:{flattenList:function(k,i){i=typeof i=="number"?i:1;var h=k.attributes,g;switch(h.type){case"a":g="lower-alpha";break;case"1":g="decimal";break}var f=k.children,e;for(var d=0;d<f.length;d++){e=f[d];if(e.name in CKEDITOR.dtd.$listItem){var c=e.attributes,b=e.children,a=b.length,m=b[a-1];if(m.name in CKEDITOR.dtd.$list){k.add(m,d+1);if(!--b.length){f.splice(d--,1)}}e.name="cke:li";h.start&&!d&&(c.value=h.start);v.filters.stylesFilter([["tab-stops",null,function(o){var n=o.split(" ")[1].match(K);n&&(x=CKEDITOR.tools.convertToPx(n[0]))}],i==1?["mso-list",null,function(o){o=o.split(" ");var n=Number(o[0].match(/\d+/));n!==w&&(c["cke:reset"]=1);w=n}]:null])(c.style);c["cke:indent"]=i;c["cke:listtype"]=k.name;c["cke:list-style-type"]=g}else{if(e.name in CKEDITOR.dtd.$list){arguments.callee.apply(this,[e,i+1]);f=f.slice(0,d).concat(e.children).concat(f.slice(d+1));k.children=[];for(var l=0,j=f.length;l<j;l++){k.add(f[l])}}}}delete k.name;h["cke:list"]=1},assembleList:function(q){var o=q.children,m,k,i,g,R,Q,t,s=[],r,p,n,l,j,h;for(var f=0;f<o.length;f++){m=o[f];if("cke:li"==m.name){m.name="li";k=m;i=k.attributes;n=i["cke:listsymbol"];n=n&&n.match(/^(?:[(]?)([^\s]+?)([.)]?)$/);l=j=h=null;if(i["cke:ignored"]){o.splice(f--,1);continue}i["cke:reset"]&&(t=R=Q=null);g=Number(i["cke:indent"]);if(g!=R){p=r=null}if(!n){l=i["cke:listtype"]||"ol";j=i["cke:list-style-type"]}else{if(p&&D[p][r].test(n[1])){l=p;j=r}else{for(var e in D){for(var d in D[e]){if(D[e][d].test(n[1])){if(e=="ol"&&/alpha|roman/.test(d)){var c=/roman/.test(d)?A(n[1]):z(n[1]);if(!h||c<h){h=c;l=e;j=d}}else{l=e;j=d;break}}}}}!l&&(l=n[2]?"ol":"ul")}p=l;r=j||(l=="ol"?"decimal":"disc");if(j&&j!=(l=="ol"?"decimal":"disc")){k.addStyle("list-style-type",j)}if(l=="ol"&&n){switch(j){case"decimal":h=Number(n[1]);break;case"lower-roman":case"upper-roman":h=A(n[1]);break;case"lower-alpha":case"upper-alpha":h=z(n[1]);break}k.attributes.value=h}if(!t){s.push(t=new CKEDITOR.htmlParser.element(l));t.add(k);o[f]=t}else{if(g>R){s.push(t=new CKEDITOR.htmlParser.element(l));t.add(k);Q.add(t)}else{if(g<R){var b=R-g,a;while(b--&&(a=t.parent)){t=a.parent}t.add(k)}else{t.add(k)}}o.splice(f--,1)}Q=k;R=g}else{if(t){t=R=Q=null}}}for(f=0;f<s.length;f++){L(s[f])}t=R=Q=w=x=y=null},falsyFilter:function(a){return false},stylesFilter:function(b,a){return function(d,c){var f=[];(d||"").replace(/&quot;/g,'"').replace(/\s*([^ :;]+)\s*:\s*([^;]+)\s*(?=;|$)/g,function(g,n,m){n=n.toLowerCase();n=="font-family"&&(m=m.replace(/["']/g,""));var l,k,j,i;for(var h=0;h<b.length;h++){if(b[h]){l=b[h][0];k=b[h][1];j=b[h][2];i=b[h][3];if(n.match(l)&&(!k||m.match(k))){n=i||n;a&&(j=j||m);if(typeof j=="function"){j=j(m,c,n)}if(j&&j.push){n=j[0],j=j[1]}if(typeof j=="string"){f.push([n,j])}return}}}!a&&f.push([n,m])});for(var e=0;e<f.length;e++){f[e]=f[e].join(":")}return f.length?f.join(";")+";":false}},elementMigrateFilter:function(b,a){return function(d){var c=a?new CKEDITOR.style(b,a)._.definition:b;d.name=c.element;CKEDITOR.tools.extend(d.attributes,CKEDITOR.tools.clone(c.attributes));d.addStyle(CKEDITOR.style.getStyleText(c))}},styleMigrateFilter:function(c,b){var a=this.elementMigrateFilter;return function(e,g){var f=new CKEDITOR.htmlParser.element(null),d={};d[b]=e;a(c,d)(f);f.children=g.children;g.children=[f]}},bogusAttrFilter:function(b,a){if(a.name.indexOf("cke:")==-1){return false}},applyStyleFilter:null},getRules:function(o){var m=CKEDITOR.dtd,k=CKEDITOR.tools.extend({},m.$block,m.$listItem,m.$tableContent),i=o.config,g=this.filters,e=g.falsyFilter,t=g.stylesFilter,s=g.elementMigrateFilter,r=CKEDITOR.tools.bind(this.filters.styleMigrateFilter,this.filters),q=this.utils.createListBulletMarker,p=g.flattenList,n=g.assembleList,l=this.utils.isListBulletIndicator,j=this.utils.isContainingOnlySpaces,h=this.utils.resolveList,f=function(P){P=CKEDITOR.tools.convertToPx(P);return isNaN(P)?P:P+"px"},d=this.utils.getStyleComponents,c=this.utils.listDtdParents,b=i.pasteFromWordRemoveFontStyles!==false,a=i.pasteFromWordRemoveStyles!==false;return{elementNames:[[/meta|link|script/,""]],root:function(P){P.filterChildren();n(P)},elements:{"^":function(R){var Q;if(CKEDITOR.env.gecko&&(Q=g.applyStyleFilter)){Q(R)}},$:function(V){var U=V.name||"",T=V.attributes;if(U in k&&T.style){T.style=t([[/^(:?width|height)$/,null,f]])(T.style)||""}if(U.match(/h\d/)){V.filterChildren();if(h(V)){return}s(i["format_"+U])(V)}else{if(U in m.$inline){V.filterChildren();if(j(V)){delete V.name}}else{if(U.indexOf(":")!=-1&&U.indexOf("cke")==-1){V.filterChildren();if(U=="v:imagedata"){var S=V.attributes["o:href"];if(S){V.attributes.src=S}V.name="img";return}delete V.name}}}if(U in c){V.filterChildren();n(V)}},style:function(V){if(CKEDITOR.env.gecko){var U=V.onlyChild().value.match(/\/\* Style Definitions \*\/([\s\S]*?)\/\*/),T=U&&U[1],S={};if(T){T.replace(/[\n\r]/g,"").replace(/(.+?)\{(.+?)\}/g,function(Y,R,Q){R=R.split(",");var P=R.length,O;for(var Z=0;Z<P;Z++){CKEDITOR.tools.trim(R[Z]).replace(/^(\w+)(\.[\w-]+)?$/g,function(aa,X,W){X=X||"*";W=W.substring(1,W.length);if(W.match(/MsoNormal/)){return}if(!S[X]){S[X]={}}if(W){S[X][W]=Q}else{S[X]=Q}})}});g.applyStyleFilter=function(R){var Q=S["*"]?"*":R.name,P=R.attributes&&R.attributes["class"],O;if(Q in S){O=S[Q];if(typeof O=="object"){O=O[P]}O&&R.addStyle(O,true)}}}}return false},p:function(V){if(/MsoListParagraph/.exec(V.attributes["class"])){var U=V.firstChild(function(O){return O.type==CKEDITOR.NODE_TEXT&&!j(O.parent)}),T=U&&U.parent,S=T&&T.attributes;S&&!S.style&&(S.style="mso-list: Ignore;")}V.filterChildren();if(h(V)){return}if(i.enterMode==CKEDITOR.ENTER_BR){delete V.name;V.add(new CKEDITOR.htmlParser.element("br"))}else{s(i["format_"+(i.enterMode==CKEDITOR.ENTER_P?"p":"div")])(V)}},div:function(V){var U=V.onlyChild();if(U&&U.name=="table"){var T=V.attributes;U.attributes=CKEDITOR.tools.extend(U.attributes,T);T.style&&U.addStyle(T.style);var S=new CKEDITOR.htmlParser.element("div");S.addStyle("clear","both");V.add(S);delete V.name}},td:function(P){if(P.getAncestor("thead")){P.name="th"}},ol:p,ul:p,dl:p,font:function(V){if(l(V.parent)){delete V.name;return}V.filterChildren();var U=V.attributes,T=U.style,S=V.parent;if("font"==S.name){CKEDITOR.tools.extend(S.attributes,V.attributes);T&&S.addStyle(T);delete V.name}else{T=T||"";if(U.color){U.color!="#000000"&&(T+="color:"+U.color+";");delete U.color}if(U.face){T+="font-family:"+U.face+";";delete U.face}if(U.size){T+="font-size:"+(U.size>3?"large":U.size<3?"small":"medium")+";";delete U.size}V.name="span";V.addStyle(T)}},span:function(ah){if(l(ah.parent)){return false}ah.filterChildren();if(j(ah)){delete ah.name;return null}if(l(ah)){var ag=ah.firstChild(function(O){return O.value||O.name=="img"}),af=ag&&(ag.value||"l."),ae=af&&af.match(/^(?:[(]?)([^\s]+?)([.)]?)$/);if(ae){var ad=q(ae,af),ac=ah.getAncestor("span");if(ac&&/ mso-hide:\s*all|display:\s*none /.test(ac.attributes.style)){ad.attributes["cke:ignored"]=1}return ad}}var ab=ah.children,aa=ah.attributes,Z=aa&&aa.style,Y=ab&&ab[0];if(Z){aa.style=t([["line-height"],[/^font-family$/,null,!b?r(i.font_style,"family"):null],[/^font-size$/,null,!b?r(i.fontSize_style,"size"):null],[/^color$/,null,!b?r(i.colorButton_foreStyle,"color"):null],[/^background-color$/,null,!b?r(i.colorButton_backStyle,"color"):null]])(Z,ah)||""}return null},b:s(i.coreStyles_bold),i:s(i.coreStyles_italic),u:s(i.coreStyles_underline),s:s(i.coreStyles_strike),sup:s(i.coreStyles_superscript),sub:s(i.coreStyles_subscript),a:function(R){var Q=R.attributes;if(Q&&!Q.href&&Q.name){delete R.name}else{if(CKEDITOR.env.webkit&&Q.href&&Q.href.match(/file:\/\/\/[\S]+#/i)){Q.href=Q.href.replace(/file:\/\/\/[^#]+/i,"")}}},"cke:listbullet":function(P){if(P.getAncestor(/h\d/)&&!i.pasteFromWordNumberedHeadingToList){delete P.name}}},attributeNames:[[/^onmouse(:?out|over)/,""],[/^onload$/,""],[/(?:v|o):\w+/,""],[/^lang/,""]],attributes:{style:t(a?[[/^list-style-type$/,null],[/^margin$|^margin-(?!bottom|top)/,null,function(V,U,T){if(U.name in {p:1,div:1}){var S=i.contentsLangDirection=="ltr"?"margin-left":"margin-right";if(T=="margin"){V=d(T,V,[S])[S]}else{if(T!=S){return null}}if(V&&!J.test(V)){return[S,V]}}return null}],[/^clear$/],[/^border.*|margin.*|vertical-align|float$/,null,function(R,Q){if(Q.name=="img"){return R}}],[/^width|height$/,null,function(R,Q){if(Q.name in {table:1,td:1,th:1,img:1}){return R}}]]:[[/^mso-/],[/-color$/,null,function(P){if(P=="transparent"){return false}if(CKEDITOR.env.gecko){return P.replace(/-moz-use-text-color/g,"transparent")}}],[/^margin$/,J],["text-indent","0cm"],["page-break-before"],["tab-stops"],["display","none"],b?[/font-?/]:null],a),width:function(R,Q){if(Q.name in m.$tableContent){return false}},border:function(R,Q){if(Q.name in m.$tableContent){return false}},"class":e,bgcolor:e,valign:a?e:function(R,Q){Q.addStyle("vertical-align",R);return false}},comment:!CKEDITOR.env.ie?function(ah,ag){var af=ah.match(/<img.*?>/),ae=ah.match(/^\[if !supportLists\]([\s\S]*?)\[endif\]$/);if(ae){var ad=ae[1]||af&&"l.",ac=ad&&ad.match(/>(?:[(]?)([^\s]+?)([.)]?)</);return q(ac,ad)}if(CKEDITOR.env.gecko&&af){var ab=CKEDITOR.htmlParser.fragment.fromHtml(af[0]).children[0],aa=ag.previous,Z=aa&&aa.value.match(/<v:imagedata[^>]*o:href=['"](.*?)['"]/),Y=Z&&Z[1];Y&&(ab.attributes.src=Y);return ab}return false}:e}}},u=function(){this.dataFilter=new CKEDITOR.htmlParser.filter()};u.prototype={toHtml:function(c){var b=CKEDITOR.htmlParser.fragment.fromHtml(c,false),a=new CKEDITOR.htmlParser.basicWriter();b.writeHtml(a,this.dataFilter);return a.getHtml(true)}};CKEDITOR.cleanWord=function(d,c){if(CKEDITOR.env.gecko){d=d.replace(/(<!--\[if[^<]*?\])-->([\S\s]*?)<!--(\[endif\]-->)/gi,"$1$2$3")}var b=new u(),a=b.dataFilter;a.addRules(CKEDITOR.plugins.pastefromword.getRules(c));c.fire("beforeCleanWord",{filter:a});try{d=b.toHtml(d,false)}catch(e){alert(c.lang.pastefromword.error)}d=d.replace(/cke:.*?".*?"/g,"");d=d.replace(/style=""/g,"");d=d.replace(/<span>/g,"");return d}})();