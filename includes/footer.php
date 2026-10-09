        </main> <!-- Fin del Main Content -->

    </div> <!-- Fin del Container Topbar + Contenido -->

</div> <!-- Fin del Container Principal Flex Lateral -->

<!-- SCRIPTS GENERALES -->
<script>
    // Toggle para Menú Lateral Móvil
    function toggleSidebar() {
        const sidebar = document.getElementById('main-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
        }
        if (overlay) {
            overlay.classList.toggle('hidden');
        }
    }

    // Toggle para Submenús desplegables
    function toggleSubmenu(id) {
        const submenu = document.getElementById(id);
        const arrow = document.getElementById('arrow-' + id);
        if (submenu) {
            submenu.classList.toggle('hidden');
        }
        if (arrow) {
            arrow.classList.toggle('rotate-180');
        }
    }

    // Global: Reset all forms and selects on page load to prevent browser caching old values
    window.addEventListener('pageshow', function(event) {
        document.querySelectorAll('form[autocomplete="off"]').forEach(function(f) { f.reset(); });
        document.querySelectorAll('select[autocomplete="off"]').forEach(function(s) {
            var defaultOpt = s.querySelector('option[selected]');
            if (defaultOpt) {
                s.value = defaultOpt.value;
            } else {
                s.selectedIndex = 0;
            }
        });
        if (event.persisted) {
            window.location.reload();
        }
    });

    // Global: Inicializar TomSelect para selects buscables
    if (typeof TomSelect !== 'undefined') {
        document.querySelectorAll('.searchable-select').forEach((el) => {
            new TomSelect(el, {
                create: false,
                sortField: { field: 'text', direction: 'asc' },
                placeholder: el.getAttribute('data-placeholder') || 'Buscar y seleccionar...'
            });
        });
    }
</script>
</body>
</html>

