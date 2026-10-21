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

    // Global: Control de Botón 'X' para limpiar campos de búsqueda
    function toggleClearBtn(inputId, btnId) {
        const inp = document.getElementById(inputId);
        const btn = document.getElementById(btnId);
        if (!inp || !btn) return;
        if (inp.value && inp.value.trim().length > 0) {
            btn.classList.remove('hidden');
            btn.classList.add('flex');
        } else {
            btn.classList.remove('flex');
            btn.classList.add('hidden');
        }
    }

    function limpiarBuscador(inputId, btnId, callback) {
        const inp = document.getElementById(inputId);
        const btn = document.getElementById(btnId);
        if (inp) {
            inp.value = '';
            inp.focus();
        }
        if (btn) {
            btn.classList.remove('flex');
            btn.classList.add('hidden');
        }
        if (typeof callback === 'function') {
            callback();
        } else if (typeof callback === 'string' && typeof window[callback] === 'function') {
            window[callback]();
        }
    }

    // Global: Paginador Dinámico de Alta Gama para Tablas y Listas Largas
    class TablePaginator {
        constructor(options) {
            this.table = typeof options.table === 'string' ? document.querySelector(options.table) : options.table;
            this.container = typeof options.container === 'string' ? document.querySelector(options.container) : options.container;
            if (!this.table || !this.container) return;

            const tableId = this.table.id || 'table_' + Math.random().toString(36).substring(2, 7);
            this.storageKey = options.storageKey || ('pag_size_' + tableId);
            
            const savedSize = localStorage.getItem(this.storageKey);
            const defaultSize = options.pageSize !== undefined ? options.pageSize : 15;
            this.pageSize = savedSize !== null ? parseInt(savedSize, 10) : defaultSize;
            this.pageSizeOptions = options.pageSizeOptions || [10, 15, 25, 50, 100, 0];
            this.itemLabel = options.itemLabel || 'registros';
            this.counterEl = typeof options.counterEl === 'string' ? document.querySelector(options.counterEl) : options.counterEl;
            this.onPageChange = options.onPageChange || null;
            
            this.instanceName = options.instanceName || ('pag_' + tableId.replace(/[^a-zA-Z0-9_]/g, '_'));
            window[this.instanceName] = this;

            this.currentPage = 1;
            this.filterPredicate = null;

            this.apply();
        }

        getRows() {
            if (!this.table) return [];
            const tbody = this.table.querySelector('tbody');
            if (!tbody) return [];
            return Array.from(tbody.querySelectorAll('tr')).filter(tr => 
                !tr.classList.contains('empty-state-row') && !tr.querySelector('td[colspan]')
            );
        }

        setFilter(predicate) {
            this.filterPredicate = predicate;
            this.currentPage = 1;
            this.apply();
        }

        setPage(page) {
            this.currentPage = page;
            this.apply();
            if (this.table) {
                const rect = this.table.getBoundingClientRect();
                if (rect.top < 0) {
                    this.table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
            if (typeof this.onPageChange === 'function') {
                this.onPageChange(this.currentPage);
            }
        }

        setPageSize(size) {
            this.pageSize = parseInt(size, 10);
            try { localStorage.setItem(this.storageKey, this.pageSize); } catch(e){}
            this.currentPage = 1;
            this.apply();
        }

        apply() {
            const allRows = this.getRows();
            if (allRows.length === 0) {
                if (this.container) this.container.innerHTML = '';
                return;
            }

            const matchedRows = allRows.filter(row => {
                if (typeof this.filterPredicate === 'function') {
                    return this.filterPredicate(row);
                }
                return true;
            });

            const totalMatched = matchedRows.length;
            const isAll = (this.pageSize === 0);
            const effectivePageSize = isAll ? (totalMatched || 1) : this.pageSize;
            const totalPages = Math.max(1, Math.ceil(totalMatched / effectivePageSize));

            if (this.currentPage > totalPages) {
                this.currentPage = totalPages;
            }
            if (this.currentPage < 1) {
                this.currentPage = 1;
            }

            const startIndex = (this.currentPage - 1) * effectivePageSize;
            const endIndex = isAll ? totalMatched : Math.min(startIndex + effectivePageSize, totalMatched);

            allRows.forEach(row => {
                row.style.display = 'none';
            });

            for (let i = startIndex; i < endIndex; i++) {
                if (matchedRows[i]) {
                    matchedRows[i].style.display = '';
                }
            }

            let emptyRow = this.table.querySelector('.empty-state-row');
            if (totalMatched === 0) {
                if (!emptyRow) {
                    const thCount = this.table.querySelectorAll('thead th').length || 10;
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'empty-state-row';
                    emptyRow.innerHTML = `<td colspan="${thCount}" class="text-center py-10 text-slate-400 font-medium">
                        <div class="flex flex-col items-center justify-center gap-2">
                            <i class="fa-solid fa-magnifying-glass text-2xl text-slate-300"></i>
                            <span>No se encontraron registros bajo los filtros actuales.</span>
                        </div>
                    </td>`;
                    const tbody = this.table.querySelector('tbody');
                    if (tbody) tbody.appendChild(emptyRow);
                } else {
                    emptyRow.style.display = '';
                }
            } else if (emptyRow) {
                emptyRow.style.display = 'none';
            }

            if (this.counterEl) {
                this.counterEl.innerText = totalMatched;
            }

            this.renderControls(totalMatched, startIndex, endIndex, totalPages, isAll);
        }

        renderControls(totalMatched, startIndex, endIndex, totalPages, isAll) {
            if (!this.container) return;

            if (totalMatched === 0) {
                this.container.innerHTML = `
                    <div class="p-4 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400 bg-slate-50/70 border-t border-slate-200">
                        <span>0 ${this.itemLabel} encontrados</span>
                    </div>
                `;
                return;
            }

            const fromNum = startIndex + 1;
            const toNum = endIndex;

            let optionsHtml = '';
            this.pageSizeOptions.forEach(opt => {
                const label = opt === 0 ? 'Todos' : opt;
                const selected = (this.pageSize === opt) ? 'selected' : '';
                optionsHtml += `<option value="${opt}" ${selected}>${label}</option>`;
            });

            let pagesHtml = '';
            if (totalPages > 1 && !isAll) {
                const prevDisabled = this.currentPage === 1;
                pagesHtml += `
                    <button type="button" 
                            ${prevDisabled ? 'disabled' : ''} 
                            onclick="window['${this.instanceName}'].setPage(${this.currentPage - 1})" 
                            class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-xs font-semibold flex items-center gap-1.5 transition shadow-sm ${prevDisabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-slate-100 cursor-pointer'}" 
                            title="Página anterior">
                        <i class="fa-solid fa-chevron-left text-[10px]"></i> <span class="hidden sm:inline">Anterior</span>
                    </button>
                `;

                const pages = this.getVisiblePages(this.currentPage, totalPages);
                pages.forEach(p => {
                    if (p === '...') {
                        pagesHtml += `<span class="w-8 h-8 flex items-center justify-center text-slate-400 font-bold select-none text-xs">...</span>`;
                    } else {
                        const isActive = (p === this.currentPage);
                        if (isActive) {
                            pagesHtml += `
                                <button type="button" class="w-8 h-8 rounded-xl font-bold text-xs flex items-center justify-center bg-[#111c44] text-white shadow-md shadow-slate-900/10 border border-slate-800">${p}</button>
                            `;
                        } else {
                            pagesHtml += `
                                <button type="button" onclick="window['${this.instanceName}'].setPage(${p})" class="w-8 h-8 rounded-xl font-bold text-xs flex items-center justify-center border border-slate-200 bg-white hover:bg-slate-100 text-slate-700 transition cursor-pointer shadow-sm">${p}</button>
                            `;
                        }
                    }
                });

                const nextDisabled = this.currentPage === totalPages;
                pagesHtml += `
                    <button type="button" 
                            ${nextDisabled ? 'disabled' : ''} 
                            onclick="window['${this.instanceName}'].setPage(${this.currentPage + 1})" 
                            class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-xs font-semibold flex items-center gap-1.5 transition shadow-sm ${nextDisabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-slate-100 cursor-pointer'}" 
                            title="Página siguiente">
                        <span class="hidden sm:inline">Siguiente</span> <i class="fa-solid fa-chevron-right text-[10px]"></i>
                    </button>
                `;
            }

            this.container.innerHTML = `
                <div class="p-4 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-600 bg-slate-50/70 border-t border-slate-200">
                    <div class="flex items-center gap-4 flex-wrap justify-center sm:justify-start">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-500 font-medium">Mostrar</span>
                            <select onchange="window['${this.instanceName}'].setPageSize(this.value)" class="bg-white border border-slate-200 rounded-xl px-2.5 py-1 text-xs font-bold text-slate-700 focus:outline-none focus:border-cyan-500 shadow-sm cursor-pointer">
                                ${optionsHtml}
                            </select>
                            <span class="text-slate-500 font-medium">por pág.</span>
                        </div>
                        <div class="text-slate-500 font-medium border-l border-slate-200 pl-4 hidden sm:block">
                            Mostrando <strong class="text-slate-800">${fromNum}</strong> - <strong class="text-slate-800">${toNum}</strong> de <strong class="text-slate-800">${totalMatched}</strong> ${this.itemLabel}
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5">
                        ${pagesHtml}
                    </div>
                </div>
            `;
        }

        getVisiblePages(current, total) {
            if (total <= 7) {
                return Array.from({ length: total }, (_, i) => i + 1);
            }
            if (current <= 4) {
                return [1, 2, 3, 4, 5, '...', total];
            }
            if (current >= total - 3) {
                return [1, '...', total - 4, total - 3, total - 2, total - 1, total];
            }
            return [1, '...', current - 1, current, current + 1, '...', total];
        }
    }
</script>
</body>
</html>

