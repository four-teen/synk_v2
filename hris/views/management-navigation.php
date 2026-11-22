<details class="hris-management-menu"<?= $view === 'accounts' && !$mobileManagement ? ' open' : '' ?>>
    <summary class="college-dean-menu-card<?= $view === 'accounts' ? ' active' : '' ?>">
        <span class="college-dean-menu-icon hris-tone-blue"><i class="bx bx-cog" aria-hidden="true"></i></span>
        <span class="college-dean-menu-title">Management</span>
        <i class="bx bx-chevron-down hris-management-chevron" aria-hidden="true"></i>
    </summary>
    <ul class="hris-management-links">
        <li><a href="accounts.php"<?= $view === 'accounts' ? ' aria-current="page"' : '' ?>><i class="bx bx-user-check" aria-hidden="true"></i> Account</a></li>
        <li><button type="button" data-open-position-catalog aria-haspopup="dialog" aria-controls="position-catalog"><i class="bx bx-list-ul" aria-hidden="true"></i> Position Catalog</button></li>
    </ul>
</details>
