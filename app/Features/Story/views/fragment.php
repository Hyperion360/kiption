<?php // app/Features/Story/views/fragment.php ?>
<?php /* The reader fragment (comp M3, infinite scroll): the response body is
       exactly ONE .chapter-unit - no layout, no head, no reader chrome -
       rendered from the partial the read page shares, so both shapes carry
       the same bytes (ChaptersFragmentTest pins the shape; the controller
       stamps X-Robots-Tag: noindex on the response). */
     echo $this->render('story/_chapter', [
         'story' => $story,
         'chapter' => $chapter,
         'position' => $position,
         'total' => $total,
         'next' => $next,
         'titles' => $titles,
         'csrf' => $csrf,
         'pct_start' => $pct_start,
         'pct_end' => $pct_end,
     ]);
