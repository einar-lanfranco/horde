<div class="header leftAlign">
 <?php echo htmlspecialchars($title) . ' (' . count($memos) . ')' ?>
 <a id="quicksearchL" href="<?php echo Horde::url('search.php') ?>" title="<?php echo _("Search") ?>" onclick="$('quicksearchL').hide(); $('quicksearch').show(); $('quicksearchT').focus(); return false;"><?php echo Horde::img('search.png', _("Search"), '') ?></a>
 <div id="quicksearch" style="display:none">
  <input type="text" name="quicksearchT" id="quicksearchT" for="notes_body" empty="notes_empty" />
  <small>
   <a title="<?php echo _("Close Search") ?>" href="#" onclick="$('quicksearch').hide(); $('quicksearchT').value = ''; QuickFinder.filter($('quicksearchT')); $('quicksearchL').show(); return false;">X</a>
   <?php echo Horde::link(Horde::url('search.php')) . _("More Options...") ?></a>
  </small>
 </div>
</div>
