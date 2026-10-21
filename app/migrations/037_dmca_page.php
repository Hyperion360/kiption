<?php // app/migrations/037_dmca_page.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // OR IGNORE preserves an operator's own /dmca page on upgrade, the
        // 036 rule. The AGENT block is operator-editable through the admin
        // page forms; hosted operators fill the platform agent, self-hosters
        // their own.
        $db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES ('dmca', 'Copyright and DMCA', ?)",
            ['Copyright notice and takedown policy.

This archive respects copyright. If you believe a work hosted here infringes your copyright, send a notice to this archive\'s operator (the contact address below) including: identification of the copyrighted work, the URL of the infringing work here, your contact information, a statement of good-faith belief that the use is unauthorized, a statement under penalty of perjury that the information is accurate and you are the owner or authorized to act, and your signature.

OPERATOR CONTACT: [fill in the operator\'s designated agent address through Admin, Pages, dmca]

We remove or disable access to material identified in a compliant notice, notify the uploader, and forward counter-notices per 17 U.S.C. 512(g). Accounts accruing three sustained notices are terminated. Uploaders confirm they hold the rights to what they post; works must be the uploader\'s own or clearly noncommercial transformative fan work of the source material.']);
    }
    public function down(Kip\Database $db): void
    {
        $db->query("DELETE FROM pages WHERE slug = 'dmca' AND body LIKE '%OPERATOR CONTACT:%'");
    }
};
