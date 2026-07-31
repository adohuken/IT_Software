        </main> <!-- Fin del Main Content -->


    </div> <!-- Fin del Flex Container Principal -->

    <!-- SCRIPTS GENERALES -->
    <script>
        // Global: Reset all forms and selects on page load to prevent browser caching old values
        window.addEventListener('pageshow', function() {
            document.querySelectorAll('form[autocomplete="off"]').forEach(function(f) { f.reset(); });
            document.querySelectorAll('select[autocomplete="off"]').forEach(function(s) {
                var defaultOpt = s.querySelector('option[selected]');
                if (defaultOpt) {
                    s.value = defaultOpt.value;
                } else {
                    s.selectedIndex = 0;
                }
            });
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
        // ════════════════════════════════════════
        // PREVENIR PROBLEMAS CON EL BOTÓN 'ATRÁS' (BFCache)
        // ════════════════════════════════════════
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                // Si la página se restauró desde el caché del navegador (al darle "Atrás"), forzar recarga
                window.location.reload();
            }
        });

    </script>
</body>
</html>
