// dashboard/dashboard.js - Versión robusta con switch entre mesas
const API_BASE = 'api/';
let grupoActual = null;
let pollingInterval = null;
let mesasActivas = [];
let mesaSeleccionadaId = null;  // ⭐ Selección por partida_id (robusto)

document.addEventListener('DOMContentLoaded', () => {
    console.log('Dashboard multi-mesa iniciado');
    cargarGrupos();
    
    // ⭐ Atajos de teclado para cambiar de mesa
    document.addEventListener('keydown', manejarAtajosTeclado);
});

// ============================================
// FUNCIONES DE GRUPOS
// ============================================

async function cargarGrupos() {
    console.log('Cargando grupos...');
    const select = document.getElementById('grupo-select');
    select.innerHTML = '<option value="">Cargando grupos...</option>';
    
    try {
        const response = await fetch(API_BASE + 'get_grupos.php');
        const data = await response.json();
        
        if (data.success && data.grupos && data.grupos.length > 0) {
            select.innerHTML = '<option value="">Seleccionar grupo...</option>';
            
            data.grupos.forEach(grupo => {
                const option = document.createElement('option');
                option.value = grupo.id;
                option.textContent = grupo.nombre;
                select.appendChild(option);
            });
            
            const savedGrupo = localStorage.getItem('dashboard_grupo_id');
            if (savedGrupo && data.grupos.find(g => g.id == savedGrupo)) {
                select.value = savedGrupo;
                cambiarGrupo();
            } else if (data.grupos.length === 1) {
                select.value = data.grupos[0].id;
                cambiarGrupo();
            }
        } else {
            select.innerHTML = '<option value="">No hay grupos disponibles</option>';
        }
    } catch (error) {
        console.error('Error cargando grupos:', error);
        select.innerHTML = '<option value="">Error al cargar grupos</option>';
    }
}

function cambiarGrupo() {
    const select = document.getElementById('grupo-select');
    grupoActual = select.value ? parseInt(select.value) : null;
    
    // ⭐ Resetear selección al cambiar de grupo
    mesaSeleccionadaId = null;
    
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }
    
    if (grupoActual) {
        localStorage.setItem('dashboard_grupo_id', grupoActual);
        document.getElementById('connection-status').innerHTML = '🟡 Conectando...';
        
        actualizarDatos();
        pollingInterval = setInterval(actualizarDatos, 3000);
        
        const mesasSelector = document.getElementById('mesas-selector');
        if (mesasSelector) mesasSelector.style.display = 'flex';
    } else {
        limpiarDashboard();
        document.getElementById('connection-status').innerHTML = '⚪ Sin grupo';
        
        const mesasSelector = document.getElementById('mesas-selector');
        if (mesasSelector) mesasSelector.style.display = 'none';
    }
}

// ============================================
// ACTUALIZACIÓN DE DATOS
// ============================================

async function actualizarDatos() {
    if (!grupoActual) return;
    
    try {
        const response = await fetch(`${API_BASE}get_partidas_activas.php?grupo_id=${grupoActual}`);
        const data = await response.json();
        
        if (data.success) {
            mesasActivas = data.mesas || [];
            renderizarSelectorMesas();
            
            if (mesasActivas.length > 0) {
                // ⭐ Buscar por ID, no por índice
                let mesaAMostrar = mesasActivas.find(m => m.partida.id === mesaSeleccionadaId);
                
                // Si la mesa seleccionada ya no existe, seleccionar la primera
                if (!mesaAMostrar) {
                    mesaAMostrar = mesasActivas[0];
                    mesaSeleccionadaId = mesaAMostrar.partida.id;
                }
                
                renderizarMesa(mesaAMostrar);
            } else {
                mostrarSinPartidas();
            }
        }
        
        const now = new Date();
        document.getElementById('last-update').innerHTML = `🕐 ${now.toLocaleTimeString()}`;
        document.getElementById('connection-status').innerHTML = '🟢 Conectado';
        document.getElementById('connection-status').className = 'connected';
        
    } catch (error) {
        console.error('Error actualizando:', error);
        document.getElementById('connection-status').innerHTML = '🔴 Error';
        document.getElementById('connection-status').className = 'disconnected';
    }
}

// ============================================
// SELECTOR DE MESAS
// ============================================

function renderizarSelectorMesas() {
    const container = document.getElementById('mesas-selector');
    if (!container) return;
    
    if (mesasActivas.length <= 1) {
        container.style.display = 'none';
        return;
    }
    
    container.style.display = 'flex';
    container.innerHTML = '';
    
    mesasActivas.forEach((mesa) => {
        const btn = document.createElement('button');
        const esActiva = mesa.partida.id === mesaSeleccionadaId;
        const enJuego = mesa.partida.hay_juego_en_curso;
        
        btn.className = `mesa-tab ${esActiva ? 'active' : ''} ${enJuego ? 'en-juego' : ''}`;
        btn.setAttribute('data-mesa-id', mesa.partida.id);
        
        // ⭐ Indicador visual de estado
        const indicador = enJuego ? '🟢' : '⏸️';
        btn.innerHTML = `${indicador} MESA ${mesa.mesa_num}`;
        
        btn.onclick = () => seleccionarMesa(mesa.partida.id);
        container.appendChild(btn);
    });
}

function seleccionarMesa(partidaId) {
    mesaSeleccionadaId = partidaId;
    renderizarSelectorMesas();
    
    const mesa = mesasActivas.find(m => m.partida.id === partidaId);
    if (mesa) {
        renderizarMesa(mesa);
    }
}

// ============================================
// ATAJOS DE TECLADO
// ============================================

function manejarAtajosTeclado(e) {
    if (mesasActivas.length <= 1) return;
    
    // Ignorar si el usuario está escribiendo en un input/select
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') {
        return;
    }
    
    const idxActual = mesasActivas.findIndex(m => m.partida.id === mesaSeleccionadaId);
    if (idxActual === -1) return;
    
    let nuevoIdx = null;
    
    // Flecha izquierda o tecla 'A': mesa anterior
    if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') {
        nuevoIdx = (idxActual - 1 + mesasActivas.length) % mesasActivas.length;
    }
    // Flecha derecha o tecla 'D': mesa siguiente
    else if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') {
        nuevoIdx = (idxActual + 1) % mesasActivas.length;
    }
    // Números 1-9: ir directo a esa mesa
    else if (/^[1-9]$/.test(e.key)) {
        const num = parseInt(e.key) - 1;
        if (num < mesasActivas.length) {
            nuevoIdx = num;
        }
    }
    
    if (nuevoIdx !== null) {
        e.preventDefault();
        seleccionarMesa(mesasActivas[nuevoIdx].partida.id);
    }
}

// ============================================
// RENDERIZADO DE MESA
// ============================================

function renderizarMesa(mesa) {
    const content = document.getElementById('mesa-content');
    if (!content) return;
    
    const jugadorMano = mesa.jugador_mano_actual || mesa.ultimo_juego?.jugador_mano || null;
    const hayJuegoEnCurso = mesa.partida.hay_juego_en_curso;
    const numRonda = mesa.partida.num_ronda || 1;
    const juegoEnRonda = mesa.partida.juego_en_ronda || 1;
    const juegosRestantesRonda = mesa.partida.juegos_restantes_ronda || 0;
    
    // Estado del juego
    let estadoJuegoTexto = '⏳ ESPERANDO SIGUIENTE JUEGO';
    let estadoJuegoColor = 'color: var(--warning);';
    
    if (hayJuegoEnCurso) {
        estadoJuegoTexto = '🟢 EN JUEGO';
        estadoJuegoColor = 'color: var(--success);';
    } else if (!mesa.ultimo_juego) {
        estadoJuegoTexto = '🆕 PRIMER JUEGO';
        estadoJuegoColor = 'color: var(--primary);';
    }
    
    // Info de ronda para triangular de 6
    let rondaInfo = '';
    if (mesa.partida.tipo === 6) {
        rondaInfo = `
            <div class="info-item">
                <div class="info-label">🔄 Ronda</div>
                <div class="info-value">${numRonda}</div>
            </div>
            <div class="info-item">
                <div class="info-label">🎯 Juego en Ronda</div>
                <div class="info-value">${juegoEnRonda}/3</div>
            </div>
            <div class="info-item">
                <div class="info-label">⏳ Restantes</div>
                <div class="info-value">${juegosRestantesRonda}</div>
            </div>
        `;
    }
    
    // Total de juegos
    let totalJuegos = 3;
    if (mesa.partida.tipo === 5) totalJuegos = 5;
    if (mesa.partida.tipo === 7) totalJuegos = 8;
    
    // ⭐ Determinar si mostrar equipos actuales o placeholder
    const tieneEquiposActuales = mesa.equipos.equipo_a.length > 0 || mesa.equipos.equipo_b.length > 0;
    
    // Renderizar equipo A
    const renderEquipoA = tieneEquiposActuales && mesa.equipos.equipo_a.length > 0
        ? mesa.equipos.equipo_a.map(nombre => `
            <div class="team-player-card">
                <div class="team-player-name">
                    ${escapeHtml(nombre)}${jugadorMano === nombre ? '<span class="mano-icon">👑</span>' : ''}
                </div>
            </div>
        `).join('')
        : '<div class="team-player-card"><div class="team-player-name">---</div></div>';
    
    // Renderizar equipo B
    const renderEquipoB = tieneEquiposActuales && mesa.equipos.equipo_b.length > 0
        ? mesa.equipos.equipo_b.map(nombre => `
            <div class="team-player-card">
                <div class="team-player-name">
                    ${escapeHtml(nombre)}${jugadorMano === nombre ? '<span class="mano-icon">👑</span>' : ''}
                </div>
            </div>
        `).join('')
        : '<div class="team-player-card"><div class="team-player-name">---</div></div>';
    
    // Marcador: si no hay juego en curso, mostrar solo referencia del último juego
    const marcadorA = hayJuegoEnCurso ? mesa.marcador.equipo_a : (mesa.ultimo_juego?.puntos_a ?? 0);
    const marcadorB = hayJuegoEnCurso ? mesa.marcador.equipo_b : (mesa.ultimo_juego?.puntos_b ?? 0);
    const marcadorHint = hayJuegoEnCurso ? '' : '<div class="marcador-hint">Último juego</div>';
    
    const html = `
        <div class="game-panel">
            <div class="panel-header">
                <h2>🎯 Partida Activa - MESA ${mesa.mesa_num}</h2>
                <div>
                    <span class="panel-badge">Ronda de ${mesa.partida.tipo}</span>
                    <span class="panel-badge" style="${estadoJuegoColor}">${estadoJuegoTexto}</span>
                </div>
            </div>
            
            <div class="info-partida">
                <div class="info-item">
                    <div class="info-label">⏱️ Tiempo</div>
                    <div class="info-value">${mesa.partida.tiempo_transcurrido}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎲 Juegos</div>
                    <div class="info-value">${mesa.partida.juegos_jugados} / ${totalJuegos}</div>
                </div>
                ${rondaInfo}
                <div class="info-item">
                    <div class="info-label">👥 Jugadores</div>
                    <div class="info-value">${mesa.partida.total_jugadores}</div>
                </div>
            </div>
            
            <!-- Scoreboard -->
            <div class="scoreboard">
                <div class="team team-a">
                    <div class="team-label">EQUIPO A</div>
                    <div class="team-score">${marcadorA}</div>
                    ${marcadorHint}
                    <div class="team-players">${renderEquipoA}</div>
                </div>
                <div class="vs-divider">VS</div>
                <div class="team team-b">
                    <div class="team-label">EQUIPO B</div>
                    <div class="team-score">${marcadorB}</div>
                    ${marcadorHint}
                    <div class="team-players">${renderEquipoB}</div>
                </div>
            </div>
            
            <!-- Último juego -->
            <div class="last-game">
                <div class="last-game-header">
                    <span>🔄 Último Juego</span>
                    <span>${mesa.ultimo_juego ? `Juego #${mesa.ultimo_juego.num_juego}` : '---'}</span>
                </div>
                <div class="last-game-content">
                    ${mesa.ultimo_juego ? `
                        <div class="last-game-winners">
                            <div class="winners-group">
                                <span class="group-label">🏆 GANADORES</span>
                                <div class="players-list">
                                    ${mesa.ultimo_juego.ganadores.map(nombre => `
                                        <div class="winner-chip">
                                            <span>🏆 ${escapeHtml(nombre)}${jugadorMano === nombre ? ' 👑' : ''}</span>
                                        </div>
                                    `).join('')}
                                </div>
                                <div class="rayas-info positive">+${mesa.ultimo_juego.rayas} rayas</div>
                            </div>
                            <div class="losers-group">
                                <span class="group-label">💔 PERDEDORES</span>
                                <div class="players-list">
                                    ${mesa.ultimo_juego.perdedores.map(nombre => `
                                        <div class="loser-chip">
                                            <span>${escapeHtml(nombre)}</span>
                                        </div>
                                    `).join('')}
                                </div>
                                <div class="rayas-info negative">-${mesa.ultimo_juego.rayas} rayas</div>
                            </div>
                        </div>
                        <div class="last-game-scores">
                            <span class="score-a">${mesa.ultimo_juego.puntos_a}</span>
                            <span>vs</span>
                            <span class="score-b">${mesa.ultimo_juego.puntos_b}</span>
                        </div>
                    ` : '<div class="last-game-winners">Esperando el primer juego...</div>'}
                </div>
            </div>
            
            <!-- Ranking de la partida -->
            <div class="live-ranking">
                <h3>🏆 Ranking de la Partida (Rayas)</h3>
                <div class="ranking-list">
                    ${mesa.jugadores.map((j, idx) => `
                        <div class="ranking-item">
                            <div class="ranking-position">${idx + 1}</div>
                            <img src="${j.img}" class="ranking-avatar" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${j.nombre}'">
                            <div class="ranking-info">
                                <div class="ranking-name">
                                    ${escapeHtml(j.nombre)}${jugadorMano === j.nombre ? '<span class="mano-icon">👑</span>' : ''}
                                </div>
                                <div class="ranking-stats">${j.juegos || 0} juegos</div>
                            </div>
                            <div class="ranking-rayas ${j.rayas >= 0 ? 'positive' : 'negative'}">
                                ${j.rayas >= 0 ? '+' : ''}${j.rayas}R
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
    
    content.innerHTML = html;
}

function mostrarSinPartidas() {
    const content = document.getElementById('mesa-content');
    if (content) {
        content.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon">🎲</div>
                <p>No hay partidas activas en este momento</p>
                <small>Inicia una partida desde la aplicación principal</small>
            </div>
        `;
    }
}

function limpiarDashboard() {
    const content = document.getElementById('mesa-content');
    if (content) content.innerHTML = '';
    document.getElementById('connection-status').innerHTML = '⚪ Sin grupo';
}

// ============================================
// UTILIDADES
// ============================================

function escapeHtml(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================
// FUNCIÓN DE DEPURACIÓN
// ============================================

window.debugDashboard = async function() {
    if (!grupoActual) {
        alert('Selecciona un grupo primero');
        return;
    }
    
    console.log('=== DEBUG DASHBOARD ===');
    console.log('Grupo actual:', grupoActual);
    console.log('Mesa seleccionada ID:', mesaSeleccionadaId);
    console.log('Mesas activas:', mesasActivas);
    
    try {
        const response = await fetch(`${API_BASE}get_partidas_activas.php?grupo_id=${grupoActual}`);
        const data = await response.json();
        console.log('Partidas activas:', data);
        alert('Partidas activas: ' + (data.total_mesas || 0) + 
              '\nSeleccionada: ' + (mesaSeleccionadaId || 'ninguna'));
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};