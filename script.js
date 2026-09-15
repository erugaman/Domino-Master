// script.js - Domino Master 9.2
const API_URL = 'api/';
const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
const RAYAS_POR_PUNTOS = { 0: 3, 50: 2, 100: 1 };
const PUNTOS_VICTORIA = 100;
const JUEGOS_POR_TIPO = { 4: 3, 5: 5, 6: 3, 7: 8 };
const JUEGOS_RONDA6_COMPLETA = [
    { a: [0,3], b: [2,4], resting: [1,5] },
    { a: [0,1], b: [5,4], resting: [2,3] },
    { a: [0,5], b: [2,3], resting: [1,4] },
    { a: [2,5], b: [1,4], resting: [0,3] },
    { a: [1,3], b: [0,4], resting: [2,5] },
    { a: [5,3], b: [0,2], resting: [1,4] },
    { a: [5,1], b: [3,4], resting: [0,2] },
    { a: [0,3], b: [2,1], resting: [4,5], especial: true }
];

// Variables globales
let players = [];
let db = [];
let active = {};
let scoresA = [], scoresB = [];
let editingId = null;
let myChart = null;
let handPlayerId = null;
let pendingNewPairs = null;
let currentPartidaId = null;
let parejasData = null;
let topActual = 'rayas_totales';

// Variables de sesión
let usuarioActual = null;
let grupoActual = null;
let jugadorActual = null;
let grupoEnEdicion = null;
let esAdmin = false;
let modalUsuarios = null;

// Variables para puntos parciales
let lastPuntosEnviados = { a: 0, b: 0 };
let timeoutGuardar = null;

// Variables para perfil
let modoEdicionPerfil = false;
let datosPerfilOriginal = {};

// ============================================
// FUNCIONES DE AUTENTICACIÓN
// ============================================

function showMessage(type, text, timeout = 3000) {
    const errorEl = document.getElementById('global-error');
    const successEl = document.getElementById('global-success');
    
    errorEl.style.display = 'none';
    successEl.style.display = 'none';
    
    if (type === 'error') {
        errorEl.style.display = 'block';
        errorEl.textContent = '❌ ' + text;
        setTimeout(() => errorEl.style.display = 'none', timeout);
    } else if (type === 'info') {
        let infoEl = document.getElementById('global-info');
        if (!infoEl) {
            infoEl = document.createElement('div');
            infoEl.id = 'global-info';
            infoEl.className = 'info-message';
            document.body.appendChild(infoEl);
        }
        infoEl.style.display = 'block';
        infoEl.textContent = 'ℹ️ ' + text;
        setTimeout(() => infoEl.style.display = 'none', timeout);
    } else {
        successEl.style.display = 'block';
        successEl.textContent = '✅ ' + text;
        setTimeout(() => successEl.style.display = 'none', timeout);
    }
}

function showLoading(show = true) {
    document.getElementById('loading-overlay').style.display = show ? 'flex' : 'none';
}

function updateProgress(percent) {
    const progressBar = document.getElementById('progress-bar');
    const progressContainer = document.getElementById('upload-progress');
    if (progressBar && progressContainer) {
        progressContainer.style.display = 'block';
        progressBar.style.width = percent + '%';
    }
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

async function apiRequest(endpoint, method = 'GET', data = null) {
    const options = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'include' };
    if (data) options.body = JSON.stringify(data);
    try {
        const res = await fetch(API_URL + endpoint, options);
        const text = await res.text();
        try { return JSON.parse(text); } 
        catch { throw new Error('Respuesta inválida del servidor'); }
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

async function iniciarSesion() {
    const username = document.getElementById('login-username').value.trim();
    const password = document.getElementById('login-password').value;
    
    if (!username || !password) {
        showMessage('error', 'Ingresa usuario y contraseña');
        return;
    }
    
    showLoading(true);
    try {
        const res = await apiRequest('login.php', 'POST', { username, password });
        if (res.success) {
            usuarioActual = res.usuario;
            grupoActual = res.usuario.grupo;
            jugadorActual = res.usuario.jugador;
            esAdmin = res.usuario.rol === 'admin';
            
            console.log('Usuario actual:', usuarioActual);
            console.log('Jugador actual:', jugadorActual);
            
            actualizarCabeceraGrupo();
            aplicarPermisosPorRol();
            
            await loadPlayers();
            await loadHistory();
            await renderRanking();
            await renderStats();
            await loadParejasStats();
            
            createSelectors();
            
            document.getElementById('screen-login').style.display = 'none';
            document.getElementById('screen-registro-grupo').style.display = 'none';
            document.getElementById('screen-setup').style.display = 'block';
            
            showMessage('success', `Bienvenido ${usuarioActual.jugador.nombre}`);
        } else {
            showMessage('error', res.error || 'Usuario o contraseña incorrectos');
        }
    } catch (error) {
        console.error('Login error:', error);
        showMessage('error', 'Error de conexión con el servidor');
    } finally {
        showLoading(false);
    }
}

function actualizarCabeceraGrupo() {
    if (grupoActual) {
        const logoDiv = document.getElementById('grupo-logo');
        const nombreSpan = document.getElementById('grupo-nombre');
        
        nombreSpan.textContent = grupoActual.nombre;
        
        if (grupoActual.logo && grupoActual.logo !== '') {
            logoDiv.innerHTML = `<img src="${grupoActual.logo}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
        } else {
            logoDiv.innerHTML = '🁠';
        }
    }
}

function aplicarPermisosPorRol() {
    const esAdministrador = (usuarioActual && usuarioActual.rol === 'admin');
    
    // Elementos solo para administradores
    const adminOnlyElements = [
        { id: 'tab-edit', type: 'tab' },
        { id: 'tab-sett', type: 'tab' },
        { id: 'btn-nuevo-jugador', type: 'button' },
        { id: 'btn-recalcular-estadisticas', type: 'button' },
        { id: 'btn-reset-history', type: 'button' },
        { id: 'btn-export-ranking', type: 'button' },
        { id: 'btn-export-history', type: 'button' },
        { id: 'btn-export-parejas', type: 'button' }
    ];
    
    const btnFinalizarDia = document.getElementById('btn-finalizar-dia');
    if (btnFinalizarDia) {
        btnFinalizarDia.style.display = esAdministrador ? 'flex' : 'none';
    }
    
    const btnAdminGrupo = document.querySelector('.btn-header.primary');
    if (btnAdminGrupo) {
        btnAdminGrupo.style.display = esAdministrador ? 'flex' : 'none';
    }
    
    // Pestaña de perfil - visible para todos
    const tabPerfil = document.getElementById('tab-perfil');
    if (tabPerfil) {
        tabPerfil.style.display = 'flex';
    }
    
    if (esAdministrador) {
        adminOnlyElements.forEach(el => {
            const element = document.getElementById(el.id);
            if (element) {
                element.style.display = 'flex';
                element.disabled = false;
            }
        });
    } else {
        adminOnlyElements.forEach(el => {
            const element = document.getElementById(el.id);
            if (element) {
                element.style.display = 'none';
            }
        });
        
        // Si está en una pestaña prohibida, redirigir
        const currentTab = document.querySelector('.tab-content[style="display: block"]')?.id;
        if (currentTab === 'view-edit' || currentTab === 'view-sett') {
            showTab('play');
        }
    }
}

async function cerrarSesion() {
    const confirmacion = confirm(
        '¿Estás seguro de que deseas cerrar sesión?\n\n' +
        'Toda la información no guardada se perderá.'
    );
    
    if (!confirmacion) return;
    
    try {
        await apiRequest('logout.php', 'POST');
        usuarioActual = null;
        grupoActual = null;
        jugadorActual = null;
        esAdmin = false;
        
        document.getElementById('screen-setup').style.display = 'none';
        document.getElementById('screen-game').style.display = 'none';
        document.getElementById('screen-login').style.display = 'block';
        document.getElementById('login-username').value = '';
        document.getElementById('login-password').value = '';
        
        showMessage('success', 'Sesión cerrada');
    } catch (error) {
        showMessage('error', 'Error al cerrar sesión');
    }
}

function mostrarRegistroGrupo() {
    document.getElementById('screen-login').style.display = 'none';
    document.getElementById('screen-registro-grupo').style.display = 'block';
}

function volverALogin() {
    document.getElementById('screen-registro-grupo').style.display = 'none';
    document.getElementById('screen-login').style.display = 'block';
}

async function registrarNuevoGrupo() {
    const nombre = document.getElementById('registro-grupo-nombre').value.trim();
    const adminNombre = document.getElementById('registro-admin-nombre').value.trim();
    const username = document.getElementById('registro-username').value.trim();
    const password = document.getElementById('registro-password').value;
    
    if (!nombre) { showMessage('error', 'Nombre del grupo requerido'); return; }
    if (!adminNombre) { showMessage('error', 'Nombre del administrador requerido'); return; }
    if (!username) { showMessage('error', 'Nombre de usuario requerido'); return; }
    if (!password || password.length < 4) { showMessage('error', 'Contraseña debe tener al menos 4 caracteres'); return; }
    
    showLoading(true);
    try {
        let logoUrl = '';
        const logoInput = document.getElementById('registro-logo-input');
        if (logoInput.files && logoInput.files[0]) {
            logoUrl = await convertirImagenABase64(logoInput.files[0]);
        }
        
        const res = await apiRequest('crear_grupo.php', 'POST', {
            nombre: nombre,
            descripcion: document.getElementById('registro-grupo-desc').value,
            logo_url: logoUrl,
            admin_nombre: adminNombre,
            admin_email: document.getElementById('registro-admin-email').value,
            username: username,
            password: password
        });
        
        if (res.success) {
            showMessage('success', 'Grupo creado exitosamente. Ahora puedes iniciar sesión.');
            volverALogin();
            document.getElementById('registro-grupo-nombre').value = '';
            document.getElementById('registro-grupo-desc').value = '';
            document.getElementById('registro-admin-nombre').value = '';
            document.getElementById('registro-admin-email').value = '';
            document.getElementById('registro-username').value = '';
            document.getElementById('registro-password').value = '';
            document.getElementById('registro-logo-input').value = '';
            document.getElementById('registro-logo-preview').innerHTML = '🁠';
        } else {
            showMessage('error', res.error || 'Error al crear grupo');
        }
    } catch (error) {
        console.error('Registro error:', error);
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

function convertirImagenABase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// ============================================
// ADMINISTRACIÓN DE GRUPO
// ============================================

function abrirAdminGrupo() {
    if (!grupoActual) return;
    
    grupoEnEdicion = grupoActual;
    
    document.getElementById('admin-grupo-nombre').value = grupoActual.nombre;
    document.getElementById('admin-grupo-desc').value = grupoActual.descripcion || '';
    
    const logoPreview = document.getElementById('admin-grupo-logo-preview');
    if (grupoActual.logo && grupoActual.logo !== '') {
        logoPreview.innerHTML = `<img src="${grupoActual.logo}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
    } else {
        logoPreview.innerHTML = '🁠';
    }
    
    document.getElementById('admin-grupo-overlay').style.display = 'flex';
}

function cerrarAdminGrupo() {
    document.getElementById('admin-grupo-overlay').style.display = 'none';
    grupoEnEdicion = null;
    document.getElementById('admin-grupo-logo-input').value = '';
    if (modalUsuarios) {
        modalUsuarios.remove();
        modalUsuarios = null;
    }
}

async function guardarCambiosGrupo() {
    const nuevoNombre = document.getElementById('admin-grupo-nombre').value.trim();
    const nuevaDesc = document.getElementById('admin-grupo-desc').value.trim();
    
    if (!nuevoNombre) {
        showMessage('error', 'El nombre del grupo es requerido');
        return;
    }
    
    let logoUrl = grupoActual.logo || '';
    const logoInput = document.getElementById('admin-grupo-logo-input');
    if (logoInput.files && logoInput.files[0]) {
        logoUrl = await convertirImagenABase64(logoInput.files[0]);
    }
    
    showLoading(true);
    try {
        const res = await apiRequest('actualizar_grupo.php', 'POST', {
            id: grupoActual.id,
            nombre: nuevoNombre,
            descripcion: nuevaDesc,
            logo_url: logoUrl
        });
        
        if (res.success) {
            grupoActual.nombre = nuevoNombre;
            grupoActual.descripcion = nuevaDesc;
            grupoActual.logo = logoUrl;
            
            actualizarCabeceraGrupo();
            showMessage('success', 'Grupo actualizado');
            cerrarAdminGrupo();
        } else {
            showMessage('error', res.error || 'Error al actualizar');
        }
    } catch (error) {
        console.error('Actualizar grupo error:', error);
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

async function eliminarGrupo() {
    if (!confirm('¿Eliminar este grupo? Se perderán TODOS los datos. Esta acción NO se puede deshacer.')) return;
    if (!confirm('⚠️ CONFIRMACIÓN FINAL: ¿Estás ABSOLUTAMENTE seguro de eliminar el grupo "' + grupoActual.nombre + '"?')) return;
    
    showLoading(true);
    try {
        const res = await apiRequest('eliminar_grupo.php', 'POST', { id: grupoActual.id });
        
        if (res.success) {
            showMessage('success', 'Grupo eliminado');
            cerrarAdminGrupo();
            cerrarSesion();
        } else {
            showMessage('error', res.error || 'Error al eliminar grupo');
        }
    } catch (error) {
        console.error('Eliminar grupo error:', error);
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

// ============================================
// ADMINISTRACIÓN DE USUARIOS
// ============================================

async function abrirAdminUsuarios() {
    if (!grupoActual) {
        showMessage('error', 'No hay grupo seleccionado');
        return;
    }
    
    showLoading(true);
    try {
        const res = await apiRequest(`admin_usuarios.php`);
        if (res.success) {
            mostrarModalUsuarios(res);
        } else {
            showMessage('error', res.error || 'Error al cargar usuarios');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

function mostrarModalUsuarios(data) {
    if (modalUsuarios) {
        modalUsuarios.remove();
    }
    
    const modal = document.createElement('div');
    modal.className = 'overlay';
    modal.style.display = 'flex';
    modal.innerHTML = `
        <div class="overlay-container">
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="color:var(--gold); margin:0; font-size:13px;">👥 Usuarios - ${escapeHtml(data.grupo_nombre)}</h3>
                    <button onclick="this.closest('.overlay').remove()" style="background:#444; color:#fff; border:none; border-radius:20px; padding:4px 10px; cursor:pointer;">✖ Cerrar</button>
                </div>
                
                <div style="margin-bottom: 12px;">
                    <h4 style="color:var(--text); margin-bottom: 6px; font-size:11px;">➕ Crear Nuevo Usuario</h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        <select id="nuevo-usuario-jugador" style="flex:2; min-width: 90px; font-size:10px; padding:6px;">
                            <option value="">Seleccionar jugador</option>
                            ${data.jugadores_disponibles.map(j => `<option value="${j.id}">${escapeHtml(j.nombre)}</option>`).join('')}
                        </select>
                        <input type="text" id="nuevo-usuario-username" placeholder="Usuario" style="flex:2; min-width: 90px; font-size:10px; padding:6px;">
                        <input type="password" id="nuevo-usuario-password" placeholder="Contraseña" style="flex:2; min-width: 90px; font-size:10px; padding:6px;">
                        <select id="nuevo-usuario-rol" style="flex:1; min-width: 65px; font-size:10px; padding:6px;">
                            <option value="user">Usuario</option>
                            <option value="admin">Admin</option>
                        </select>
                        <button onclick="crearUsuario()" style="background:var(--success); color:#000; padding:6px 10px; border-radius:6px; font-size:10px;">Crear</button>
                    </div>
                </div>
                
                <div style="border-top: 1px solid #333; padding-top: 10px;">
                    <h4 style="color:var(--text); margin-bottom: 6px; font-size:11px;">📋 Usuarios Existentes</h4>
                    <div style="max-height: 300px; overflow-y: auto;">
                        ${data.usuarios.map(u => `
                            <div class="player-item" style="cursor: default; margin-bottom: 5px; padding: 6px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0;">
                                    <img src="${u.jugador_avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${u.username}`}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
                                </div>
                                <div class="player-info">
                                    <div class="player-name" style="font-size:11px;">${escapeHtml(u.username)}</div>
                                    <div style="font-size: 8px; color: var(--gray);">
                                        ${escapeHtml(u.jugador_nombre)} · ${u.rol === 'admin' ? '👑 Admin' : '👤 User'}
                                        ${!u.activo ? ' · <span style="color:var(--danger);">INACTIVO</span>' : ''}
                                    </div>
                                </div>
                                <div style="display: flex; gap: 3px;">
                                    <button onclick="cambiarPasswordUsuario(${u.id})" style="background:var(--primary); color:#fff; padding:3px 6px; border-radius:5px; font-size:9px;">🔑</button>
                                    ${u.rol === 'admin' ? 
                                        `<button onclick="cambiarRolUsuario(${u.id}, 'user')" style="background:var(--warning); color:#000; padding:3px 6px; border-radius:5px; font-size:9px;">👤</button>` :
                                        `<button onclick="cambiarRolUsuario(${u.id}, 'admin')" style="background:var(--warning); color:#000; padding:3px 6px; border-radius:5px; font-size:9px;">👑</button>`
                                    }
                                    ${u.activo ? 
                                        `<button onclick="eliminarUsuario(${u.id})" style="background:var(--danger); color:#fff; padding:3px 6px; border-radius:5px; font-size:9px;">🗑️</button>` : ''
                                    }
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    modalUsuarios = modal;
}

async function crearUsuario() {
    const jugadorId = document.getElementById('nuevo-usuario-jugador').value;
    const username = document.getElementById('nuevo-usuario-username').value.trim();
    const password = document.getElementById('nuevo-usuario-password').value;
    const rol = document.getElementById('nuevo-usuario-rol').value;
    
    if (!jugadorId) {
        showMessage('error', 'Selecciona un jugador');
        return;
    }
    if (!username) {
        showMessage('error', 'Ingresa un nombre de usuario');
        return;
    }
    if (!password || password.length < 4) {
        showMessage('error', 'La contraseña debe tener al menos 4 caracteres');
        return;
    }
    
    showLoading(true);
    try {
        const res = await apiRequest('admin_usuarios.php', 'POST', {
            accion: 'crear',
            jugador_id: parseInt(jugadorId),
            username: username,
            password: password,
            rol: rol
        });
        
        if (res.success) {
            showMessage('success', 'Usuario creado correctamente');
            if (modalUsuarios) modalUsuarios.remove();
            abrirAdminUsuarios();
        } else {
            showMessage('error', res.error || 'Error al crear usuario');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

async function cambiarPasswordUsuario(usuarioId) {
    const nuevaPassword = prompt('Ingresa la nueva contraseña (mínimo 4 caracteres):');
    if (!nuevaPassword) return;
    if (nuevaPassword.length < 4) {
        showMessage('error', 'La contraseña debe tener al menos 4 caracteres');
        return;
    }
    
    showLoading(true);
    try {
        const res = await apiRequest('admin_usuarios.php', 'POST', {
            accion: 'cambiar_password',
            usuario_id: usuarioId,
            nueva_password: nuevaPassword
        });
        
        if (res.success) {
            showMessage('success', 'Contraseña cambiada correctamente');
            if (modalUsuarios) modalUsuarios.remove();
            abrirAdminUsuarios();
        } else {
            showMessage('error', res.error || 'Error al cambiar contraseña');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

async function cambiarRolUsuario(usuarioId, nuevoRol) {
    if (!confirm(`¿Cambiar el rol de este usuario a ${nuevoRol === 'admin' ? 'Administrador' : 'Usuario'}?`)) return;
    
    showLoading(true);
    try {
        const res = await apiRequest('admin_usuarios.php', 'POST', {
            accion: 'cambiar_rol',
            usuario_id: usuarioId,
            rol: nuevoRol
        });
        
        if (res.success) {
            showMessage('success', 'Rol actualizado correctamente');
            if (modalUsuarios) modalUsuarios.remove();
            abrirAdminUsuarios();
        } else {
            showMessage('error', res.error || 'Error al cambiar rol');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

async function eliminarUsuario(usuarioId) {
    if (!confirm('¿Desactivar este usuario? Podrá ser reactivado más tarde.')) return;
    
    showLoading(true);
    try {
        const res = await apiRequest('admin_usuarios.php', 'DELETE', {
            usuario_id: usuarioId
        });
        
        if (res.success) {
            showMessage('success', 'Usuario desactivado');
            if (modalUsuarios) modalUsuarios.remove();
            abrirAdminUsuarios();
        } else {
            showMessage('error', res.error || 'Error al eliminar usuario');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

document.getElementById('admin-grupo-logo-input').onchange = function(e) {
    const file = e.target.files[0];
    if (file && file.type.match('image.*')) {
        const reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('admin-grupo-logo-preview').innerHTML = `<img src="${ev.target.result}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
        };
        reader.readAsDataURL(file);
    }
};

document.getElementById('registro-logo-input').onchange = function(e) {
    const file = e.target.files[0];
    if (file && file.type.match('image.*')) {
        const reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('registro-logo-preview').innerHTML = `<img src="${ev.target.result}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
        };
        reader.readAsDataURL(file);
    }
};

// ============================================
// FUNCIONES DE API
// ============================================

async function crearPartidaEnMySQL(tipoRonda, jugadores) {
    showLoading(true);
    try {
        const result = await apiRequest('crear_partida.php', 'POST', {
            tipo: tipoRonda,
            jugadores: jugadores.map(j => ({ id: j.id, n: j.n }))
        });
        if (result.success) return result.partida_id;
        throw new Error(result.error || 'Error al crear partida');
    } catch (error) {
        showMessage('error', error.message);
        throw error;
    } finally { showLoading(false); }
}

async function guardarJuegoEnMySQL(juegoData) {
    showLoading(true);
    try {
        const result = await apiRequest('guardar_juego.php', 'POST', juegoData);
        if (!result.success) throw new Error(result.error);
        showMessage('success', `Juego ${juegoData.num_juego} guardado`);
        return result.juego_id;
    } catch (error) {
        showMessage('error', error.message);
        throw error;
    } finally { showLoading(false); }
}

async function guardarPuntosParciales() {
    if (!currentPartidaId || !active.currentMatch) return;
    
    const totalA = scoresA.reduce((a,b)=>a+b,0);
    const totalB = scoresB.reduce((a,b)=>a+b,0);
    
    if (totalA === lastPuntosEnviados.a && totalB === lastPuntosEnviados.b) return;
    
    lastPuntosEnviados = { a: totalA, b: totalB };
    
    const equipoAJugadores = active.currentMatch.a.map(i => active.jugadores[i].n);
    const equipoBJugadores = active.currentMatch.b.map(i => active.jugadores[i].n);
    
    const jugadorManoId = active.handPlayerId || null;
    
    // ============================================
    // ⭐ USAR LA RONDA CORRECTA
    // ============================================
    // Para triangular de 6, usar active.numRonda
    // Para otros tipos, usar 1
    const ronda = active.tipo === 6 ? (active.numRonda || 1) : 1;
    
    console.log(`Guardando puntos - Juego ${active.juegoNum}, Ronda ${ronda}`);
    
    if (timeoutGuardar) clearTimeout(timeoutGuardar);
    
    timeoutGuardar = setTimeout(async () => {
        try {
            const result = await apiRequest('guardar_puntos_parciales.php', 'POST', {
                partida_id: currentPartidaId,
                juego_num: active.juegoNum,
                ronda: ronda,
                equipo_a_puntos: totalA,
                equipo_b_puntos: totalB,
                equipo_a_jugadores: equipoAJugadores.join(','),
                equipo_b_jugadores: equipoBJugadores.join(','),
                jugador_mano_id: jugadorManoId
            });
            console.log('Puntos parciales guardados - Ronda:', ronda, result);
        } catch (error) {
            console.error('Error guardando puntos parciales:', error);
        }
    }, 500);
}

async function limpiarPuntosParciales() {
    if (!currentPartidaId) return;
    try {
        await apiRequest('limpiar_puntos.php', 'POST', {
            partida_id: currentPartidaId
        });
        console.log('Puntos parciales limpiados para partida:', currentPartidaId);
    } catch(e) { 
        console.error('Error limpiando puntos:', e); 
    }
}

async function loadPlayers() {
    if (!grupoActual) return;
    try {
        const result = await apiRequest(`get_players.php?grupo_id=${grupoActual.id}`);
        if (result.success) players = result.players;
        renderPlayerList();
        updateOptions();
    } catch (error) {
        console.error('Error cargando jugadores:', error);
    }
}

async function loadHistory() {
    if (!grupoActual) return;
    try {
        const result = await apiRequest(`get_history.php?grupo_id=${grupoActual.id}`);
        if (result.success) {
            db = result.history;
            if (document.getElementById('view-hist').style.display !== 'none') renderHistory();
        }
    } catch (error) {
        console.error('Error cargando historial:', error);
    }
}

// ============================================
// FUNCIONES PARA PERFIL DE USUARIO
// ============================================

async function cargarPerfil() {
    console.log('Cargando perfil...');
    console.log('jugadorActual:', jugadorActual);
    
    const container = document.getElementById('perfil-info');
    
    if (!jugadorActual || !jugadorActual.id) {
        console.error('No hay jugador actual');
        container.innerHTML = '<div style="text-align:center; color:var(--danger); padding:20px;">Error: No se encontró tu información de usuario. Intenta cerrar sesión y volver a entrar.</div>';
        return;
    }
    
    try {
        const res = await apiRequest(`get_players.php?grupo_id=${grupoActual.id}`);
        console.log('Respuesta get_players:', res);
        
        if (res.success && res.players) {
            const miJugador = res.players.find(p => p.id == jugadorActual.id);
            console.log('Mi jugador encontrado:', miJugador);
            
            if (miJugador) {
                datosPerfilOriginal = { ...miJugador };
                renderizarPerfil(miJugador);
            } else {
                container.innerHTML = '<div style="text-align:center; color:var(--danger); padding:20px;">No se encontraron tus datos. Contacta al administrador.</div>';
            }
        } else {
            container.innerHTML = '<div style="text-align:center; color:var(--danger); padding:20px;">Error al cargar tus datos: ' + (res.error || 'desconocido') + '</div>';
        }
    } catch (error) {
        console.error('Error cargando perfil:', error);
        container.innerHTML = '<div style="text-align:center; color:var(--danger); padding:20px;">Error de conexión: ' + error.message + '</div>';
    }
}

function renderizarPerfil(jugador) {
    const container = document.getElementById('perfil-info');
    
    if (modoEdicionPerfil) {
        container.innerHTML = `
            <div class="perfil-avatar" style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                <img src="${jugador.img}" id="perfil-avatar-img" style="width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--gold); object-fit: cover;" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${jugador.name}'">
                <button onclick="document.getElementById('perfil-file-input').click()" style="background: var(--primary); border: none; border-radius: 8px; padding: 5px 12px; font-size: 11px; cursor: pointer;">📷 Cambiar foto</button>
            </div>
            <input type="file" id="perfil-file-input" style="display:none" accept="image/*" onchange="cambiarFotoPerfil(this)">
            
            <div class="perfil-field">
                <label>📛 Nombre</label>
                <input type="text" id="perfil-nombre" value="${escapeHtml(jugador.name)}">
            </div>
            <div class="perfil-field">
                <label>📧 Email</label>
                <input type="email" id="perfil-email" value="${escapeHtml(jugador.email || '')}">
            </div>
            <div class="perfil-field">
                <label>📱 Teléfono</label>
                <input type="tel" id="perfil-telefono" value="${escapeHtml(jugador.phone || '')}">
            </div>
            <div class="perfil-field">
                <label>🎂 Fecha de Nacimiento</label>
                <input type="date" id="perfil-bday" value="${jugador.bday || ''}">
            </div>
            
            <div class="perfil-password-section">
                <h4>🔐 Cambiar Contraseña</h4>
                <div class="perfil-field">
                    <label>Nueva Contraseña</label>
                    <input type="password" id="perfil-new-password" placeholder="Nueva contraseña (mínimo 4 caracteres)">
                </div>
                <div class="perfil-field">
                    <label>Confirmar Contraseña</label>
                    <input type="password" id="perfil-confirm-password" placeholder="Confirmar nueva contraseña">
                </div>
            </div>
            
            <div class="perfil-buttons">
                <div class="perfil-btn-cancel" onclick="cancelarEdicionPerfil()">Cancelar</div>
                <div class="perfil-btn-save" onclick="guardarPerfil()">💾 Guardar Cambios</div>
            </div>
            <div id="perfil-message" class="perfil-message" style="display:none;"></div>
        `;
    } else {
        container.innerHTML = `
            <div class="perfil-avatar" style="display: flex; justify-content: center;">
                <img src="${jugador.img}" style="width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--gold); object-fit: cover;" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${jugador.name}'">
            </div>
            <div class="perfil-field">
                <label>📛 Nombre</label>
                <input type="text" value="${escapeHtml(jugador.name)}" disabled>
            </div>
            <div class="perfil-field">
                <label>📧 Email</label>
                <input type="email" value="${escapeHtml(jugador.email || 'No registrado')}" disabled>
            </div>
            <div class="perfil-field">
                <label>📱 Teléfono</label>
                <input type="tel" value="${escapeHtml(jugador.phone || 'No registrado')}" disabled>
            </div>
            <div class="perfil-field">
                <label>🎂 Fecha de Nacimiento</label>
                <input type="text" value="${jugador.bday || 'No registrada'}" disabled>
            </div>
            <div class="perfil-buttons">
                <div class="perfil-btn-save" onclick="activarEdicionPerfil()">✏️ Editar Perfil</div>
            </div>
        `;
    }
}

function activarEdicionPerfil() {
    modoEdicionPerfil = true;
    cargarPerfil();
}

function cancelarEdicionPerfil() {
    modoEdicionPerfil = false;
    cargarPerfil();
}

async function cambiarFotoPerfil(input) {
    const file = input.files[0];
    if (!file) return;
    
    if (file.size > MAX_IMAGE_SIZE) {
        mostrarMensajePerfil('error', `La imagen es demasiado grande (${formatFileSize(file.size)}). Máximo 5MB`);
        input.value = '';
        return;
    }
    
    if (!file.type.match('image.*')) {
        mostrarMensajePerfil('error', 'Solo se permiten imágenes');
        input.value = '';
        return;
    }
    
    try {
        const base64 = await convertirImagenABase64(file);
        const imgPreview = document.getElementById('perfil-avatar-img');
        if (imgPreview) {
            imgPreview.src = base64;
        }
        window.nuevaFotoPerfil = base64;
        mostrarMensajePerfil('success', 'Foto actualizada. Guarda los cambios para aplicar.');
    } catch (error) {
        mostrarMensajePerfil('error', 'Error al procesar la imagen');
    }
}

function mostrarMensajePerfil(tipo, texto) {
    const msgDiv = document.getElementById('perfil-message');
    if (msgDiv) {
        msgDiv.className = `perfil-message ${tipo}`;
        msgDiv.textContent = tipo === 'error' ? '❌ ' + texto : '✅ ' + texto;
        msgDiv.style.display = 'block';
        setTimeout(() => {
            msgDiv.style.display = 'none';
        }, 3000);
    }
}

async function guardarPerfil() {
    const nombre = document.getElementById('perfil-nombre')?.value.trim();
    const email = document.getElementById('perfil-email')?.value.trim();
    const telefono = document.getElementById('perfil-telefono')?.value.trim();
    const bday = document.getElementById('perfil-bday')?.value;
    const nuevaPassword = document.getElementById('perfil-new-password')?.value;
    const confirmPassword = document.getElementById('perfil-confirm-password')?.value;
    
    if (!nombre) {
        mostrarMensajePerfil('error', 'El nombre es requerido');
        return;
    }
    
    if (nuevaPassword) {
        if (nuevaPassword.length < 4) {
            mostrarMensajePerfil('error', 'La contraseña debe tener al menos 4 caracteres');
            return;
        }
        if (nuevaPassword !== confirmPassword) {
            mostrarMensajePerfil('error', 'Las contraseñas no coinciden');
            return;
        }
    }
    
    showLoading(true);
    
    try {
        const jugadorData = {
            id: jugadorActual.id,
            name: nombre,
            email: email,
            phone: telefono,
            bday: bday,
            img: window.nuevaFotoPerfil || datosPerfilOriginal.img
        };
        
        console.log('Guardando jugador:', jugadorData);
        const resJugador = await apiRequest('save_player.php', 'POST', jugadorData);
        
        if (!resJugador.success) {
            throw new Error(resJugador.error || 'Error al actualizar perfil');
        }
        
        if (nuevaPassword) {
            const resPass = await apiRequest('cambiar_password.php', 'POST', {
                usuario_id: usuarioActual.id,
                nueva_password: nuevaPassword
            });
            if (!resPass.success) {
                throw new Error(resPass.error || 'Error al cambiar contraseña');
            }
        }
        
        if (window.nuevaFotoPerfil) {
            jugadorActual.img = window.nuevaFotoPerfil;
        }
        jugadorActual.nombre = nombre;
        
        mostrarMensajePerfil('success', 'Perfil actualizado correctamente');
        
        const passInput = document.getElementById('perfil-new-password');
        const confirmInput = document.getElementById('perfil-confirm-password');
        if (passInput) passInput.value = '';
        if (confirmInput) confirmInput.value = '';
        
        window.nuevaFotoPerfil = null;
        modoEdicionPerfil = false;
        
        await cargarPerfil();
        await loadPlayers();
        
    } catch (error) {
        mostrarMensajePerfil('error', error.message);
    } finally {
        showLoading(false);
    }
}

// ============================================
// ESTADÍSTICAS DE PAREJAS
// ============================================

function mostrarTop(tipo) {
    topActual = tipo;
    document.querySelectorAll('.top-btn').forEach(btn => btn.classList.remove('active'));
    const btnActivo = document.getElementById(`btn-${tipo}`);
    if (btnActivo) btnActivo.classList.add('active');
    
    if (!parejasData) {
        document.getElementById('top-rayas-content').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay datos cargados</div>';
        return;
    }
    
    let datos = [];
    switch(tipo) {
        case 'rayas_totales': datos = parejasData.top_rayas_totales || []; break;
        case 'rayas_ganadas': datos = parejasData.top_rayas_ganadas || []; break;
        case 'rayas_perdidas': datos = parejasData.top_rayas_perdidas || []; break;
        default: datos = [];
    }
    
    if (datos.length === 0) {
        document.getElementById('top-rayas-content').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay suficientes datos</div>';
        return;
    }
    
    let html = '';
    datos.forEach((p, i) => {
        const valor = tipo === 'rayas_totales' ? (p.rayas_totales || 0) :
                     tipo === 'rayas_ganadas' ? (p.rayas_ganadas || 0) :
                     (p.rayas_perdidas || 0);
        const diferencia = p.diferencia || p.diferencia_rayas || p.rayas_totales || 0;
        const victorias = p.victorias || 0;
        const derrotas = p.derrotas || 0;
        const veces = p.veces_jugadas || 0;
        let color = tipo === 'rayas_perdidas' ? 'var(--danger)' : 'var(--success)';
        const nombre1 = p.jugador1_nombre || 'Jugador';
        const nombre2 = p.jugador2_nombre || 'Jugador';
        const img1 = p.jugador1_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${nombre1}`;
        const img2 = p.jugador2_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${nombre2}`;
        
        html += `
            <div class="pareja-card" style="border-left: 3px solid ${color}; margin-bottom: 6px;">
                <div style="width:25px; font-size:13px; font-weight:bold; color:var(--gray);">#${i+1}</div>
                <div class="pareja-avatars">
                    <img src="${img1}" style="width:32px;height:32px;border-radius:50%;">
                    <img src="${img2}" style="width:32px;height:32px;border-radius:50%;margin-left:-8px;">
                </div>
                <div class="pareja-info">
                    <div class="pareja-nombre">${escapeHtml(nombre1.split(' ')[0])} & ${escapeHtml(nombre2.split(' ')[0])}</div>
                    <div class="pareja-stats">${victorias}V ${derrotas}D · ${veces}J</div>
                </div>
                <div class="pareja-record" style="color:${color}; font-weight:bold;">${valor >= 0 ? '+' : ''}${valor}R</div>
            </div>
        `;
    });
    document.getElementById('top-rayas-content').innerHTML = html;
}

async function loadParejasStats() {
    if (!grupoActual) return;
    try {
        showLoading(true);
        const res = await apiRequest(`get_stats_parejas.php?grupo_id=${grupoActual.id}`);
        
        if (!res.success) {
            document.getElementById('mejores-parejas').innerHTML = '<div style="text-align:center; color:var(--danger); padding:12px;">Error: ' + (res.error || 'Cargando estadísticas') + '</div>';
            document.getElementById('tabla-parejas').innerHTML = '<div style="text-align:center; color:var(--danger); padding:12px;">Error cargando datos</div>';
            return;
        }
        
        parejasData = res;
        
        if (!res.parejas || res.parejas.length === 0) {
            document.getElementById('mejores-parejas').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay datos de parejas. Juega algunas partidas primero.</div>';
            document.getElementById('peores-parejas').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay datos de parejas</div>';
            document.getElementById('resumen-parejas').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay datos suficientes</div>';
            document.getElementById('tabla-parejas').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay datos de parejas</div>';
            document.getElementById('top-rayas-content').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay suficientes datos</div>';
            return;
        }
        
        // ============================================
        // MEJORES PAREJAS
        // ============================================
        let mejoresHtml = '';
        const mejores = res.mejores_parejas || [];
        mejores.forEach((p, i) => {
            const diferencia = p.diferencia || p.diferencia_rayas || p.rayas_totales || 0;
            const nombre1 = p.jugador1_nombre || 'Jugador';
            const nombre2 = p.jugador2_nombre || 'Jugador';
            const img1 = p.jugador1_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre1)}`;
            const img2 = p.jugador2_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre2)}`;
            const victorias = p.victorias || 0;
            const derrotas = p.derrotas || 0;
            const porcentaje = p.porcentaje_victorias || 0;
            const rayasGanadas = p.rayas_ganadas || 0;
            const rayasPerdidas = p.rayas_perdidas || 0;
            
            mejoresHtml += `
                <div class="pareja-card" style="border-left: 3px solid var(--success); margin-bottom: 8px;">
                    <div style="width:30px; font-size:14px; font-weight:bold; color:var(--gold);">#${i+1}</div>
                    <div class="pareja-avatars">
                        <img src="${img1}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre1)}'" style="width:36px;height:36px;border-radius:50%;">
                        <img src="${img2}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre2)}'" style="width:36px;height:36px;border-radius:50%;margin-left:-8px;">
                    </div>
                    <div class="pareja-info">
                        <div class="pareja-nombre">${escapeHtml(nombre1)} & ${escapeHtml(nombre2)}</div>
                        <div class="pareja-stats">${victorias}V ${derrotas}D · ${porcentaje}%</div>
                        <div class="pareja-stats" style="font-size:8px;">
                            <span style="color:var(--success);">+${rayasGanadas}R</span> / 
                            <span style="color:var(--danger);">-${rayasPerdidas}R</span>
                        </div>
                    </div>
                    <div class="pareja-record" style="color:${diferencia >= 0 ? 'var(--success)' : 'var(--danger)'}; font-weight:bold;">
                        ${diferencia >= 0 ? '+' : ''}${diferencia}R
                    </div>
                </div>
            `;
        });
        document.getElementById('mejores-parejas').innerHTML = mejoresHtml || '<div style="text-align:center; color:var(--gray); padding:12px;">No hay suficientes datos</div>';
        
        // ============================================
        // PEORES PAREJAS
        // ============================================
        let peoresHtml = '';
        const peores = res.peores_parejas || [];
        peores.forEach((p, i) => {
            const diferencia = p.diferencia || p.diferencia_rayas || p.rayas_totales || 0;
            const nombre1 = p.jugador1_nombre || 'Jugador';
            const nombre2 = p.jugador2_nombre || 'Jugador';
            const img1 = p.jugador1_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre1)}`;
            const img2 = p.jugador2_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre2)}`;
            const victorias = p.victorias || 0;
            const derrotas = p.derrotas || 0;
            const porcentaje = p.porcentaje_victorias || 0;
            const rayasGanadas = p.rayas_ganadas || 0;
            const rayasPerdidas = p.rayas_perdidas || 0;
            
            peoresHtml += `
                <div class="pareja-card" style="border-left: 3px solid var(--danger); margin-bottom: 8px;">
                    <div style="width:30px; font-size:14px; font-weight:bold; color:var(--gray);">#${i+1}</div>
                    <div class="pareja-avatars">
                        <img src="${img1}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre1)}'" style="width:36px;height:36px;border-radius:50%;">
                        <img src="${img2}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre2)}'" style="width:36px;height:36px;border-radius:50%;margin-left:-8px;">
                    </div>
                    <div class="pareja-info">
                        <div class="pareja-nombre">${escapeHtml(nombre1)} & ${escapeHtml(nombre2)}</div>
                        <div class="pareja-stats">${victorias}V ${derrotas}D · ${porcentaje}%</div>
                        <div class="pareja-stats" style="font-size:8px;">
                            <span style="color:var(--success);">+${rayasGanadas}R</span> / 
                            <span style="color:var(--danger);">-${rayasPerdidas}R</span>
                        </div>
                    </div>
                    <div class="pareja-record" style="color:${diferencia >= 0 ? 'var(--success)' : 'var(--danger)'}; font-weight:bold;">
                        ${diferencia >= 0 ? '+' : ''}${diferencia}R
                    </div>
                </div>
            `;
        });
        document.getElementById('peores-parejas').innerHTML = peoresHtml || '<div style="text-align:center; color:var(--gray); padding:12px;">No hay suficientes datos</div>';
        
        // ============================================
        // RESUMEN DE PAREJAS
        // ============================================
        let resumenHtml = '<div class="stats-grid">';
        resumenHtml += `<div class="stat-card"><div class="stat-title">Total Parejas</div><div class="stat-value">${res.total_parejas || 0}</div><div class="stat-desc">combinaciones diferentes</div></div>`;
        resumenHtml += `<div class="stat-card"><div class="stat-title">Juegos Analizados</div><div class="stat-value">${res.total_juegos_analizados || 0}</div><div class="stat-desc">partidos entre parejas</div></div>`;
        resumenHtml += `<div class="stat-card"><div class="stat-title">Rayas Totales</div><div class="stat-value ${(res.total_rayas_neta || 0) >= 0 ? 'txt-pos' : 'txt-neg'}">${(res.total_rayas_neta || 0) >= 0 ? '+' : ''}${res.total_rayas_neta || 0}</div><div class="stat-desc"><span style="color:var(--success);">+${res.total_rayas_ganadas || 0}</span> / <span style="color:var(--danger);">-${res.total_rayas_perdidas || 0}</span></div></div>`;
        if (res.mejor_pareja) {
            const diff = res.mejor_pareja.diferencia || 0;
            const nombre1 = res.mejor_pareja.jugador1_nombre || 'Jugador';
            const nombre2 = res.mejor_pareja.jugador2_nombre || 'Jugador';
            resumenHtml += `<div class="stat-card" style="border-left-color:var(--gold);"><div class="stat-title">🏆 Mejor Pareja</div><div class="stat-value">${res.mejor_pareja.victorias}V / ${res.mejor_pareja.derrotas}D</div><div class="stat-desc">${escapeHtml(nombre1)} & ${escapeHtml(nombre2)}<br><span style="color:var(--success);">${diff >= 0 ? '+' : ''}${diff}R</span></div></div>`;
        }
        if (res.peor_pareja) {
            const diff = res.peor_pareja.diferencia || 0;
            const nombre1 = res.peor_pareja.jugador1_nombre || 'Jugador';
            const nombre2 = res.peor_pareja.jugador2_nombre || 'Jugador';
            resumenHtml += `<div class="stat-card" style="border-left-color:var(--danger);"><div class="stat-title">💔 Peor Pareja</div><div class="stat-value">${res.peor_pareja.victorias}V / ${res.peor_pareja.derrotas}D</div><div class="stat-desc">${escapeHtml(nombre1)} & ${escapeHtml(nombre2)}<br><span style="color:var(--danger);">${diff >= 0 ? '+' : ''}${diff}R</span></div></div>`;
        }
        resumenHtml += '</div>';
        document.getElementById('resumen-parejas').innerHTML = resumenHtml;
        
        // ============================================
        // TABLA COMPLETA DE PAREJAS (CORREGIDA)
        // ============================================
        const parejasOrdenadas = [...(res.parejas || [])];
        parejasOrdenadas.sort((a, b) => {
            if (a.veces_jugadas !== b.veces_jugadas) return b.veces_jugadas - a.veces_jugadas;
            return (b.rayas_totales || 0) - (a.rayas_totales || 0);
        });
        
        let tablaHtml = `
            <div style="overflow-x: auto;">
                <table class="parejas-table" style="width:100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #2c2c2e; border-bottom: 2px solid var(--gold);">
                            <th style="padding: 10px 8px; text-align: left; color: var(--gold);">Pareja</th>
                            <th style="padding: 10px 8px; text-align: center; color: var(--gold);">J</th>
                            <th style="padding: 10px 8px; text-align: center; color: var(--gold);">V-D</th>
                            <th style="padding: 10px 8px; text-align: center; color: var(--gold);">%</th>
                            <th style="padding: 10px 8px; text-align: center; color: var(--success);">R+</th>
                            <th style="padding: 10px 8px; text-align: center; color: var(--danger);">R-</th>
                            <th style="padding: 10px 8px; text-align: center; color: var(--gold);">±</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        for (const p of parejasOrdenadas) {
            const diferencia = p.diferencia || p.diferencia_rayas || p.rayas_totales || 0;
            const nombre1 = p.jugador1_nombre || 'Jugador';
            const nombre2 = p.jugador2_nombre || 'Jugador';
            const img1 = p.jugador1_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre1)}`;
            const img2 = p.jugador2_img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre2)}`;
            const victorias = p.victorias || 0;
            const derrotas = p.derrotas || 0;
            const porcentaje = p.porcentaje_victorias || 0;
            const rayasGanadas = p.rayas_ganadas || 0;
            const rayasPerdidas = p.rayas_perdidas || 0;
            
            tablaHtml += `
                <tr style="border-bottom: 1px solid #333;">
                    <td style="padding: 8px 8px; text-align: left;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="display: flex; align-items: center;">
                                <img src="${img1}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre1)}'" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover;">
                                <span style="margin: 0 2px; color: var(--text-muted);">+</span>
                                <img src="${img2}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(nombre2)}'" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover;">
                            </div>
                            <div style="display: flex; flex-direction: column; font-size: 10px; line-height: 1.3;">
                                <span style="color: var(--text);">${escapeHtml(nombre1)}</span>
                                <span style="color: var(--text);">${escapeHtml(nombre2)}</span>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 8px 8px; text-align: center;">${p.veces_jugadas}</td>
                    <td style="padding: 8px 8px; text-align: center;">${victorias}-${derrotas}</td>
                    <td style="padding: 8px 8px; text-align: center;">${porcentaje}%</td>
                    <td style="padding: 8px 8px; text-align: center; color: var(--success);">+${rayasGanadas}</td>
                    <td style="padding: 8px 8px; text-align: center; color: var(--danger);">-${rayasPerdidas}</td>
                    <td style="padding: 8px 8px; text-align: center; ${diferencia >= 0 ? 'color: var(--success);' : 'color: var(--danger);'} font-weight: bold;">
                        ${diferencia >= 0 ? '+' : ''}${diferencia}
                    </td>
                </tr>
            `;
        }
        
        tablaHtml += `
                    </tbody>
                </table>
            </div>
        `;
        
        document.getElementById('tabla-parejas').innerHTML = tablaHtml;
        
        // ============================================
        // TOP RAYAS
        // ============================================
        mostrarTop(topActual);
        
    } catch (error) {
        console.error('Error cargando estadísticas de parejas:', error);
        document.getElementById('mejores-parejas').innerHTML = '<div style="text-align:center; color:var(--danger); padding:12px;">Error cargando datos: ' + error.message + '</div>';
        document.getElementById('peores-parejas').innerHTML = '<div style="text-align:center; color:var(--danger); padding:12px;">Error cargando datos</div>';
        document.getElementById('tabla-parejas').innerHTML = '<div style="text-align:center; color:var(--danger); padding:12px;">Error cargando datos</div>';
    } finally {
        showLoading(false);
    }
}

async function recalcularEstadisticas() {
    if (!confirm('¿Recalcular todas las estadísticas? Esto puede tomar unos segundos.')) return;
    
    showLoading(true);
    try {
        const res = await apiRequest('recalcular_estadisticas.php', 'POST');
        if (res.success) {
            showMessage('success', res.message);
            await renderStats();
            await loadParejasStats();
            await renderRanking();
        } else {
            showMessage('error', res.error || 'Error al recalcular estadísticas');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

// ============================================
// SELECTOR DE MANO
// ============================================

function showHandSelector() {
    const eqA = [], eqB = [], desc = [];
    if (active?.currentMatch) {
        active.currentMatch.a.forEach(i => eqA.push(active.jugadores[i]));
        active.currentMatch.b.forEach(i => eqB.push(active.jugadores[i]));
        if (active.currentMatch.resting) active.currentMatch.resting.forEach(i => desc.push(active.jugadores[i]));
    }
    
    const listDiv = document.getElementById('hand-selector-list');
    listDiv.innerHTML = '';
    
    if (!eqA.length && !eqB.length) {
        const n = +document.getElementById('round-type').value;
        const numSelectores = n === 7 ? 6 : n;
        const names = Array.from({length:numSelectores}, (_,i) => document.getElementById('sel-'+i).value);
        
        if (n === 4) names.forEach((name,i) => {
            const p = players.find(p=>p.name===name);
            if (p) (i<2?eqA:eqB).push({n:p.name,id:p.id,img:p.img});
        });
        else if (n === 5) names.forEach((name,i) => {
            const p = players.find(p=>p.name===name);
            if (p) {
                if (i<2) eqA.push({n:p.name,id:p.id,img:p.img});
                else if (i<4) eqB.push({n:p.name,id:p.id,img:p.img});
                else desc.push({n:p.name,id:p.id,img:p.img});
            }
        });
        else if (n === 6 && active.p1 && active.p2) {
            active.p1.forEach(i => eqA.push(active.jugadores[i]));
            active.p2.forEach(i => eqB.push(active.jugadores[i]));
            if (active.p3) active.p3.forEach(i => desc.push(active.jugadores[i]));
        }
        else if (n === 7 && active.currentMatch) {
            active.currentMatch.a.forEach(i => eqA.push(active.jugadores[i]));
            active.currentMatch.b.forEach(i => eqB.push(active.jugadores[i]));
            if (active.currentMatch.resting) active.currentMatch.resting.forEach(i => desc.push(active.jugadores[i]));
        }
    }
    
    const card = (player) => {
        if (!player) return '';
        const p = players.find(p=>p.name===player.n)||player;
        return `<div class="selector-player-card" onclick="selectHandPlayer('${p.id||player.id}')" id="player-${p.id||player.id}">
            <img src="${p.img}" class="selector-player-avatar" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${p.id||p.n}'">
            <span class="selector-player-name">${escapeHtml(p.n||p.name)}</span>
            <div class="selector-hand-indicator">👑</div>
        </div>`;
    };
    
    let html = '';
    if (eqA.length) html += `<div class="selector-team"><div class="selector-team-header"><span>⚔️</span><span>EQUIPO A</span></div><div class="selector-players-grid">${eqA.map(card).join('')}</div></div>`;
    if (eqB.length) html += `<div class="selector-team"><div class="selector-team-header"><span>🛡️</span><span>EQUIPO B</span></div><div class="selector-players-grid">${eqB.map(card).join('')}</div></div>`;
    if (desc.length) html += `<div class="selector-team"><div class="selector-team-header"><span>⏳</span><span>DESCANSA</span></div><div class="selector-players-grid">${desc.map(card).join('')}</div></div>`;
    
    listDiv.innerHTML = html;
    document.getElementById('hand-selector-overlay').style.display = 'flex';
    handPlayerId = null;
}

function selectHandPlayer(id) {
    document.querySelectorAll('.selector-player-card').forEach(e => e.classList.remove('selected'));
    document.getElementById(`player-${id}`)?.classList.add('selected');
    handPlayerId = id;
}

function closeHandSelector() {
    if (!handPlayerId) { showMessage('error', 'Selecciona la mano'); return; }
    document.getElementById('hand-selector-overlay').style.display = 'none';
    active.handPlayerId = handPlayerId;
    prepareStarter();
    document.getElementById('screen-setup').style.display = 'none';
    document.getElementById('screen-game').style.display = 'block';
}

// ============================================
// INICIO DE RONDA
// ============================================

async function beginRonda() {
    const n = +document.getElementById('round-type').value;
    const numSelectores = n === 7 ? 6 : n;
    const names = Array.from({length:numSelectores}, (_,i) => document.getElementById('sel-'+i).value);
    
    if (names.includes('')) { 
        showMessage('error', 'Selecciona todos los jugadores'); 
        return; 
    }
    
    if (n === 7 && names.length !== 6) {
        showMessage('error', 'La Ronda de 6 Completa requiere 6 jugadores');
        return;
    }
    
    // ============================================
    // ⭐ FORZAR CREACIÓN DE NUEVA PARTIDA
    // ============================================
    // Si hay una partida activa, asegurarse de que se cree una nueva
    // NO reutilizar currentPartidaId de partidas anteriores
    if (currentPartidaId) {
        // Verificar si la partida actual ya está completada o abandonada
        try {
            const checkRes = await apiRequest(`verificar_partida.php?partida_id=${currentPartidaId}`);
            if (checkRes && checkRes.estado === 'completada') {
                // La partida anterior ya está completada, forzar nuevo ID
                currentPartidaId = null;
            }
        } catch(e) {
            // Si hay error, forzar nuevo ID
            currentPartidaId = null;
        }
    }
    
    handPlayerId = null;
    active = {
        tipo: n,
        fecha: new Date().toLocaleString(),
        jugadores: names.map(n => {
            const p = players.find(p=>p.name===n);
            return { n, r:0, j:0, id:p?.id, email:p?.email, phone:p?.phone, img:p?.img||'' };
        }),
        juegoNum: 1,
        log: [],
        handPlayerId: null
    };
    
    if (n === 6) {
        active.numRonda = 1;
        active.usedPairs = [];
        active.allPairCombinations = generateAllPairs();
        active.p1 = [0,1]; active.p2 = [2,3]; active.p3 = [4,5];
        active.usedPairs.push('0-1','2-3','4-5');
        ultimoDescanso = null;
    }
    
    try {
        // Crear una NUEVA partida en la base de datos
        currentPartidaId = await crearPartidaEnMySQL(n, active.jugadores);
        console.log('Nueva partida creada con ID:', currentPartidaId);
        prepareStarterForHandSelection();
    } catch (e) {
        console.error('Error creando partida:', e);
        showMessage('error', 'No se pudo crear la partida: ' + e.message);
    }
}

function prepareStarterForHandSelection() {
    const n = active.tipo, j = active.juegoNum;
    
    scoresA = [];
    scoresB = [];
    lastPuntosEnviados = { a: 0, b: 0 };
    
    if (currentPartidaId) {
        limpiarPuntosParciales();
    }
    
    if (n === 4) { 
        const c = [[0,1,2,3],[0,2,1,3],[0,3,1,2]][j-1]; 
        active.currentMatch = { a: [c[0],c[1]], b: [c[2],c[3]], resting: [] };
    }
    else if (n === 5) { 
        const c = [[0,1,2,3,4],[0,2,3,4,1],[0,3,4,1,2],[0,4,1,2,3],[2,4,1,3,0]][j-1]; 
        active.currentMatch = { a: [c[0],c[1]], b: [c[2],c[3]], resting: [c[4]] };
    }
    else if (n === 6) { 
        if (j === 1) { 
            active.currentMatch = { a: active.p1, b: active.p2, resting: active.p3 };
        }
        else if (j === 2) { 
            active.currentMatch = { a: active.lastWinner, b: active.p3, resting: active.lastLoser };
        }
        else { 
            active.currentMatch = { a: active.lastLoser, b: active.p3, resting: active.lastWinner };
        } 
    }
    else if (n === 7) {
        const juego = JUEGOS_RONDA6_COMPLETA[j-1];
        active.currentMatch = { 
            a: juego.a, 
            b: juego.b, 
            resting: juego.resting,
            especial: juego.especial || false
        };
    }
    
    showHandSelector();
}

function prepareStarter() {
    if (active.currentMatch.especial) {
        const warningDiv = document.getElementById('descanso-info');
        warningDiv.innerHTML = `
            <div class="special-warning">
                <div class="special-title">⚠️ REGLA ESPECIAL ⚠️</div>
                <div>En este juego SOLO cuentan los puntos y rayas de:</div>
                <div class="special-players">
                    <div class="rest-player-item">
                        <img src="${players.find(x => x.name === active.jugadores[2].n)?.img}" class="rest-img">
                        <span>${escapeHtml(active.jugadores[2].n)}</span>
                    </div>
                    <div class="rest-player-item">
                        <img src="${players.find(x => x.name === active.jugadores[1].n)?.img}" class="rest-img">
                        <span>${escapeHtml(active.jugadores[1].n)}</span>
                    </div>
                </div>
                <div style="font-size:9px; margin-top:4px;">
                    Los jugadores ${escapeHtml(active.jugadores[0].n)} y ${escapeHtml(active.jugadores[3].n)} NO reciben puntos ni rayas
                </div>
            </div>
        `;
    } else {
        const dDiv = document.getElementById('descanso-info');
        if (active.currentMatch.resting?.length) {
            let html = `<div class="descanso-badge"><span>Descansa:</span>`;
            active.currentMatch.resting.forEach(i => {
                const p = players.find(x => x.name === active.jugadores[i].n);
                if(p) {
                    html += `<div class="rest-player-item"><img src="${p.img}" class="rest-img"> <span>${escapeHtml(p.name)}</span></div>`;
                }
            });
            dDiv.innerHTML = html + `</div>`;
        } else {
            dDiv.innerHTML = '';
        }
    }
    loadMatch();
}

function loadMatch() {
    scoresA = [];
    scoresB = [];
    lastPuntosEnviados = { a: 0, b: 0 };
    
    recalc('A'); 
    recalc('B');
    document.getElementById('btn-next').style.display = 'none';
    
    if (currentPartidaId && active.juegoNum) {
        limpiarPuntosParciales();
    }
    
    const totalJuegos = active.tipo == 7 ? 8 : (active.tipo == 5 ? 5 : 3);
    document.getElementById('game-number').innerText = `Juego ${active.juegoNum}/${totalJuegos}`;
    
    const draw = (inds) => inds.map(i => {
        const p = players.find(x => x.name === active.jugadores[i].n);
        const isHand = p?.id == active.handPlayerId;
        return `<div class="avatar-container"><img src="${p?.img||''}" class="game-avatar">${isHand?'<span class="star-badge">👑</span>':''}</div>`;
    }).join('');
    
    document.getElementById('game-imgs-a').innerHTML = draw(active.currentMatch.a);
    document.getElementById('game-imgs-b').innerHTML = draw(active.currentMatch.b);
    document.getElementById('names-a').innerText = active.currentMatch.a.map(i=>active.jugadores[i].n).join(" & ");
    document.getElementById('names-b').innerText = active.currentMatch.b.map(i=>active.jugadores[i].n).join(" & ");
    
    if (active.tipo === 6) {
        document.getElementById('game-header').innerText = `Ronda ${active.numRonda} - Juego ${active.juegoNum}`;
    } else if (active.tipo === 7) {
        document.getElementById('game-header').innerText = `Ronda Completa - Juego ${active.juegoNum}/8${active.currentMatch.especial ? ' ⚠️ REGLA ESPECIAL' : ''}`;
    } else {
        document.getElementById('game-header').innerText = `Juego ${active.juegoNum} (Ronda de ${active.tipo})`;
    }
}

// ============================================
// GESTIÓN DE PUNTOS
// ============================================

function sumPts(t) {
    const inp = document.getElementById('pts-'+t.toLowerCase());
    const v = parseInt(inp.value);
    if (isNaN(v) || v<=0) { showMessage('error', 'Número inválido'); return; }
    (t==='A'?scoresA:scoresB).push(v);
    recalc(t);
    inp.value = '';
}

function sumEmpate(t) {
    (t==='A'?scoresA:scoresB).push(0);
    recalc(t);
}

function deletePoint(t, idx) {
    (t==='A'?scoresA:scoresB).splice(idx,1);
    recalc(t);
}

function recalc(t) {
    const list = t==='A'?scoresA:scoresB;
    const total = list.reduce((a,b)=>a+b,0);
    document.getElementById('score-'+t.toLowerCase()).innerText = total;
    
    let running = 0;
    document.getElementById('list-'+t.toLowerCase()).innerHTML = list.map((v,i)=>{
        running += v;
        return `<div class="points-entry" onclick="deletePoint('${t}',${i})"><span>${v===0?'EMPATE':'+'+v}</span><span>(${running})<span class="delete-point">✖</span></span></div>`;
    }).reverse().join('');
    
    const totalA = scoresA.reduce((a,b)=>a+b,0);
    const totalB = scoresB.reduce((a,b)=>a+b,0);
    document.getElementById('btn-next').style.display = (totalA>=PUNTOS_VICTORIA || totalB>=PUNTOS_VICTORIA) ? 'block' : 'none';
    
    // Guardar puntos parciales en tiempo real
    guardarPuntosParciales();
}

// ============================================
// REGISTRAR JUEGO
// ============================================

async function registrarJuego() {
    const totalA = scoresA.reduce((a,b)=>a+b,0);
    const totalB = scoresB.reduce((a,b)=>a+b,0);
    
    if (!totalA && !totalB) { 
        showMessage('error', 'Ingresa puntos'); 
        return; 
    }
    
    const winA = totalA > totalB;
    const ptsW = winA ? totalA : totalB;
    const ptsL = winA ? totalB : totalA;
    
    let r;
    if (ptsL <= 0) r = 3;
    else if (ptsL < 51) r = 2;
    else r = 1;
    
    const wIdx = winA ? active.currentMatch.a : active.currentMatch.b;
    const lIdx = winA ? active.currentMatch.b : active.currentMatch.a;
    
    if (active.currentMatch.especial) {
        wIdx.forEach(i => {
            if (i === 1 || i === 2) {
                active.jugadores[i].r += r; 
                active.jugadores[i].j++; 
            }
        });
        lIdx.forEach(i => {
            if (i === 1 || i === 2) {
                active.jugadores[i].r -= r; 
                active.jugadores[i].j++; 
            }
        });
        showMessage('info', '⚠️ Solo los jugadores ' + active.jugadores[1].n + ' y ' + active.jugadores[2].n + ' reciben puntos y rayas');
    } else {
        wIdx.forEach(i => { 
            active.jugadores[i].r += r; 
            active.jugadores[i].j++; 
        });
        lIdx.forEach(i => { 
            active.jugadores[i].r -= r; 
            active.jugadores[i].j++; 
        });
    }
    
    const handPlayer = players.find(p => p.id == active.handPlayerId);
    
    const juegoData = {
        partida_id: currentPartidaId,
        num_juego: active.juegoNum,
        num_ronda: active.numRonda || 1,
        ganadores: wIdx.map(i => active.jugadores[i].n),
        perdedores: lIdx.map(i => active.jugadores[i].n),
        pts_ganador: ptsW,
        pts_perdedor: ptsL,
        rayas: r,
        jugador_mano: handPlayer ? handPlayer.name : '',
        especial: active.currentMatch.especial || false,
        jugadores_especiales: active.currentMatch.especial ? [active.jugadores[1].n, active.jugadores[2].n] : []
    };
    
    try {
        await guardarJuegoEnMySQL(juegoData);
        
        // ============================================
        // ⭐ LIMPIAR PUNTOS PARCIALES
        // ============================================
        // Importante: No borrar, solo poner a 0
        await limpiarPuntosParciales();
        
        // Resetear variables locales
        scoresA = [];
        scoresB = [];
        lastPuntosEnviados = { a: 0, b: 0 };
        
        // Notificar al dashboard
        try {
            localStorage.setItem('domino_actualizar_dashboard', Date.now().toString());
            setTimeout(() => localStorage.removeItem('domino_actualizar_dashboard'), 500);
        } catch(e) { console.error('Error notificando dashboard:', e); }
        
        active.log.push({
            j: active.juegoNum,
            gan: wIdx.map(i => ({ nombre: active.jugadores[i].n })),
            per: lIdx.map(i => ({ nombre: active.jugadores[i].n })),
            r, ptsW, ptsL,
            numRonda: active.numRonda || 1,
            resting: active.currentMatch.resting || [],
            mano: handPlayer ? handPlayer.name : '',
            especial: active.currentMatch.especial || false
        });
        
        if (active.juegoNum === 1) {
            active.lastWinner = wIdx;
            active.lastLoser = lIdx;
        }
        
        // ============================================
        // ⭐ GUARDAR QUIÉNES DESCANSARON EN EL JUEGO 3
        // ============================================
        if (active.tipo === 6 && active.currentMatch.resting && active.currentMatch.resting.length === 2) {
            if (active.juegoNum === 3) {
                ultimoDescanso = [...active.currentMatch.resting];
                console.log('✅ Descanso registrado para próxima ronda:', ultimoDescanso.map(i => active.jugadores[i].n));
            }
        }
        
        // Incrementar juegoNum
        active.juegoNum++;
        
        const limit = JUEGOS_POR_TIPO[active.tipo];
        
        if (active.juegoNum <= limit) {
            // Preparar siguiente juego (misma ronda)
            recalc('A');
            recalc('B');
            prepareStarterForHandSelection();
            document.getElementById('btn-next').innerText = 'REGISTRAR JUEGO';
        } else {
            // Fin de la ronda o de la partida
            document.getElementById('btn-next').style.display = 'none';
            
            if (active.tipo === 6) {
                let nuevas = getUniquePairs();
                if (nuevas) {
                    showNextRoundInfo(nuevas);
                } else {
                    showMessage('info', 'Fin de la partida');
                    finalizarRonda();
                }
            } else {
                finalizarRonda();
            }
        }
        
    } catch (error) {
        console.error('Error guardando juego:', error);
        showMessage('error', `Error guardando juego ${active.juegoNum}: ${error.message}`);
    }
}


async function registrarJuego() {
    const totalA = scoresA.reduce((a,b)=>a+b,0);
    const totalB = scoresB.reduce((a,b)=>a+b,0);
    
    if (!totalA && !totalB) { 
        showMessage('error', 'Ingresa puntos'); 
        return; 
    }
    
    const winA = totalA > totalB;
    const ptsW = winA ? totalA : totalB;
    const ptsL = winA ? totalB : totalA;
    
    let r;
    if (ptsL <= 0) r = 3;
    else if (ptsL < 51) r = 2;
    else r = 1;
    
    const wIdx = winA ? active.currentMatch.a : active.currentMatch.b;
    const lIdx = winA ? active.currentMatch.b : active.currentMatch.a;
    
    if (active.currentMatch.especial) {
        wIdx.forEach(i => {
            if (i === 1 || i === 2) {
                active.jugadores[i].r += r; 
                active.jugadores[i].j++; 
            }
        });
        lIdx.forEach(i => {
            if (i === 1 || i === 2) {
                active.jugadores[i].r -= r; 
                active.jugadores[i].j++; 
            }
        });
        showMessage('info', '⚠️ Solo los jugadores ' + active.jugadores[1].n + ' y ' + active.jugadores[2].n + ' reciben puntos y rayas');
    } else {
        wIdx.forEach(i => { 
            active.jugadores[i].r += r; 
            active.jugadores[i].j++; 
        });
        lIdx.forEach(i => { 
            active.jugadores[i].r -= r; 
            active.jugadores[i].j++; 
        });
    }
    
    const handPlayer = players.find(p => p.id == active.handPlayerId);
    
    const juegoData = {
        partida_id: currentPartidaId,
        num_juego: active.juegoNum,
        num_ronda: active.numRonda || 1,
        ganadores: wIdx.map(i => active.jugadores[i].n),
        perdedores: lIdx.map(i => active.jugadores[i].n),
        pts_ganador: ptsW,
        pts_perdedor: ptsL,
        rayas: r,
        jugador_mano: handPlayer ? handPlayer.name : '',
        especial: active.currentMatch.especial || false,
        jugadores_especiales: active.currentMatch.especial ? [active.jugadores[1].n, active.jugadores[2].n] : []
    };
    
    try {
        await guardarJuegoEnMySQL(juegoData);
        
        await limpiarPuntosParciales();
        
        scoresA = [];
        scoresB = [];
        lastPuntosEnviados = { a: 0, b: 0 };
        
        try {
            localStorage.setItem('domino_actualizar_dashboard', Date.now().toString());
            setTimeout(() => localStorage.removeItem('domino_actualizar_dashboard'), 500);
        } catch(e) { console.error('Error notificando dashboard:', e); }
        
        active.log.push({
            j: active.juegoNum,
            gan: wIdx.map(i => ({ nombre: active.jugadores[i].n })),
            per: lIdx.map(i => ({ nombre: active.jugadores[i].n })),
            r, ptsW, ptsL,
            numRonda: active.numRonda || 1,
            resting: active.currentMatch.resting || [],
            mano: handPlayer ? handPlayer.name : '',
            especial: active.currentMatch.especial || false
        });
        
        if (active.juegoNum === 1) {
            active.lastWinner = wIdx;
            active.lastLoser = lIdx;
        }
        
        // ============================================
        // ⭐ GUARDAR QUIÉNES DESCANSARON EN EL JUEGO 3 (ANTES DE INCREMENTAR)
        // ============================================
        // Guardar los jugadores que descansaron en el juego 3
        if (active.tipo === 6 && active.currentMatch.resting && active.currentMatch.resting.length === 2) {
            // Verificar si es el juego 3 (último juego de la ronda)
            // En ronda de 6 triangular, el juego 3 es el último de la ronda
            if (active.juegoNum === 3) {
                ultimoDescanso = [...active.currentMatch.resting];
                console.log('✅ Descanso registrado para próxima ronda:', ultimoDescanso.map(i => active.jugadores[i].n));
            }
        }
        
        // Incrementar juegoNum
        active.juegoNum++;
        
        const limit = JUEGOS_POR_TIPO[active.tipo];
        
        if (active.juegoNum <= limit) {
            recalc('A');
            recalc('B');
            prepareStarterForHandSelection();
            document.getElementById('btn-next').innerText = 'REGISTRAR JUEGO';
        } else {
            document.getElementById('btn-next').style.display = 'none';
            
            if (active.tipo === 6) {
                let nuevas = getUniquePairs();
                if (nuevas) {
                    showNextRoundInfo(nuevas);
                } else {
                    showMessage('info', 'Fin de la partida');
                    finalizarRonda();
                }
            } else {
                finalizarRonda();
            }
        }
        
    } catch (error) {
        console.error('Error guardando juego:', error);
        showMessage('error', `Error guardando juego ${active.juegoNum}: ${error.message}`);
    }
}

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function generateAllPairs() {
    const pairs = [];
    for (let i=0; i<5; i++) for (let j=i+1; j<6; j++) pairs.push([i,j]);
    const valid = [];
    for (let a=0; a<pairs.length; a++)
        for (let b=a+1; b<pairs.length; b++)
            for (let c=b+1; c<pairs.length; c++)
                if (new Set([...pairs[a],...pairs[b],...pairs[c]]).size === 6)
                    valid.push({ p1:pairs[a].sort(), p2:pairs[b].sort(), p3:pairs[c].sort() });
    return valid;
}

function getUniquePairs() {
    if (!active.allPairCombinations) active.allPairCombinations = generateAllPairs();
    
    console.log('=== getUniquePairs ===');
    console.log('ultimoDescanso:', ultimoDescanso);
    
    if (ultimoDescanso) {
        console.log('🔄 Jugadores que descansaron en juego 3:', ultimoDescanso.map(i => active.jugadores[i].n));
    }
    
    // Filtrar combinaciones que no repitan parejas usadas
    let avail = active.allPairCombinations.filter(c => 
        !active.usedPairs.includes(c.p1.join('-')) &&
        !active.usedPairs.includes(c.p2.join('-')) &&
        !active.usedPairs.includes(c.p3.join('-'))
    );
    
    console.log('Combinaciones disponibles:', avail.length);
    
    if (avail.length === 0) {
        showMessage('info', '⚠️ No es posible formar nuevas parejas sin repetir combinaciones anteriores. El juego ha terminado.');
        return null;
    }
    
    let seleccion = avail[Math.floor(Math.random() * avail.length)];
    
    // ============================================
    // ⭐ REGLA DINÁMICA: Los que descansaron en juego 3 juegan el juego 1
    // ============================================
    if (ultimoDescanso && ultimoDescanso.length === 2) {
        const d1 = ultimoDescanso[0];
        const d2 = ultimoDescanso[1];
        
        console.log(`🎯 Buscando combo donde ${active.jugadores[d1].n} y ${active.jugadores[d2].n} JUGUEN (no descansen)`);
        
        // Buscar una combinación donde d1 y d2 NO estén descansando juntos
        // Es decir, que estén en las parejas que juegan (p1 o p2)
        let mejorOpcion = null;
        
        for (let combo of avail) {
            const descansan = combo.p3;
            // Si los que descansaron NO están en el descanso de esta combo, es válida
            if (!descansan.includes(d1) && !descansan.includes(d2)) {
                mejorOpcion = combo;
                console.log('✅ Combo encontrado:', combo);
                break;
            }
        }
        
        // Si no se encontró una combo donde ambos jueguen, buscar donde al menos uno juegue
        if (!mejorOpcion) {
            for (let combo of avail) {
                const descansan = combo.p3;
                if (!descansan.includes(d1) || !descansan.includes(d2)) {
                    mejorOpcion = combo;
                    console.log('⚠️ Combo parcial encontrado (al menos uno juega):', combo);
                    break;
                }
            }
        }
        
        if (mejorOpcion) {
            seleccion = mejorOpcion;
            console.log(`✅ REGLA DINÁMICA APLICADA: ${active.jugadores[d1].n} y ${active.jugadores[d2].n} JUGARÁN el juego 1`);
        } else {
            console.log('⚠️ No se encontró combo donde jueguen, usando selección aleatoria');
        }
    }
    
    // Limpiar ultimoDescanso después de usarlo (solo aplica una vez)
    ultimoDescanso = null;
    
    console.log('🎲 Selección final:', seleccion);
    return seleccion;
}

function showNextRoundInfo(nuevas) {
    pendingNewPairs = nuevas;
    
    const get = i => {
        const p = active.jugadores[i];
        const pl = players.find(x=>x.name===p.n);
        return { name:p.n, img:pl?.img||`https://api.dicebear.com/7.x/avataaars/svg?seed=${p.n}` };
    };
    
    const jugadorA1 = get(nuevas.p1[0]);
    const jugadorA2 = get(nuevas.p1[1]);
    const jugadorB1 = get(nuevas.p2[0]);
    const jugadorB2 = get(nuevas.p2[1]);
    const descanso1 = get(nuevas.p3[0]);
    const descanso2 = get(nuevas.p3[1]);
    
    document.getElementById('next-round-info').innerHTML = `
        <div class="next-round-info">
            <div class="next-round-title">🎲 Próximos Enfrentamientos</div>
            
            <div class="next-round-pair">
                <div class="pair-team">
                    <div class="pair-player">
                        <img src="${jugadorA1.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${jugadorA1.name}'">
                        <span class="player-name">${escapeHtml(jugadorA1.name)}</span>
                    </div>
                    <div class="pair-player">
                        <img src="${jugadorA2.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${jugadorA2.name}'">
                        <span class="player-name">${escapeHtml(jugadorA2.name)}</span>
                    </div>
                </div>
                <div class="vs-badge">VS</div>
                <div class="pair-team">
                    <div class="pair-player">
                        <img src="${jugadorB1.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${jugadorB1.name}'">
                        <span class="player-name">${escapeHtml(jugadorB1.name)}</span>
                    </div>
                    <div class="pair-player">
                        <img src="${jugadorB2.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${jugadorB2.name}'">
                        <span class="player-name">${escapeHtml(jugadorB2.name)}</span>
                    </div>
                </div>
                
                <div class="rest-section">
                    <div class="rest-title">⏳ Descansan</div>
                    <div class="rest-players">
                        <div class="rest-player">
                            <img src="${descanso1.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${descanso1.name}'">
                            <span class="player-name">${escapeHtml(descanso1.name)}</span>
                        </div>
                        <div class="rest-player">
                            <img src="${descanso2.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${descanso2.name}'">
                            <span class="player-name">${escapeHtml(descanso2.name)}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.getElementById('next-round-overlay').style.display = 'flex';
}

function cancelNextRound() {
    document.getElementById('next-round-overlay').style.display = 'none';
    pendingNewPairs = null;
    finalizarRonda();
}

function confirmNextRound() {
    document.getElementById('next-round-overlay').style.display = 'none';
    if (!pendingNewPairs) return;
    
    active.p1 = pendingNewPairs.p1;
    active.p2 = pendingNewPairs.p2;
    active.p3 = pendingNewPairs.p3;
    active.usedPairs.push(pendingNewPairs.p1.join('-'), pendingNewPairs.p2.join('-'), pendingNewPairs.p3.join('-'));
    
    // ============================================
    // ⭐ INCREMENTAR RONDA (solo para triangular de 6)
    // ============================================
    if (active.tipo === 6) {
        active.numRonda = (active.numRonda || 0) + 1;
        console.log('🔄 Nueva Ronda:', active.numRonda);
    }
    
    active.juegoNum = 1;
    active.lastWinner = null;
    active.lastLoser = null;
    handPlayerId = null;
    
    // Resetear puntos
    scoresA = [];
    scoresB = [];
    lastPuntosEnviados = { a: 0, b: 0 };
    
    if (currentPartidaId) {
        limpiarPuntosParciales();
    }
    
    prepareStarterForHandSelection();
}

async function finalizarRonda() {
    if (currentPartidaId) {
        await limpiarPuntosParciales();
        try {
            await apiRequest('finalizar_partida.php', 'POST', {
                partida_id: currentPartidaId
            });
        } catch (error) {
            console.error('Error al finalizar partida:', error);
        }
    }
    
    document.getElementById('screen-game').style.display = 'none';
    document.getElementById('screen-setup').style.display = 'block';
    showTab('play');
    showMessage('success', 'Ronda completada');
    
    await loadHistory();
    await loadParejasStats();
    await renderRanking();
    
    currentPartidaId = null;
    active = {};
}

async function abandonarRonda() {
    // Confirmación con detalles
    const confirmacion = confirm(
        '⚠️ ¿ABANDONAR PARTIDA? ⚠️\n\n' +
        'Esta acción:\n' +
        '• Guardará los juegos registrados hasta ahora\n' +
        '• Cerrará la partida actual\n' +
        '• No se podrá deshacer\n\n' +
        '¿Estás seguro de que deseas abandonar la partida?'
    );
    
    if (!confirmacion) return;
    
    // Si hay partida activa, finalizarla
    if (currentPartidaId) {
        await limpiarPuntosParciales();
        try {
            await apiRequest('finalizar_partida.php', 'POST', {
                partida_id: currentPartidaId
            });
            console.log('Partida finalizada por abandono:', currentPartidaId);
        } catch (error) {
            console.error('Error al finalizar partida:', error);
        }
    }
    
    // Limpiar variables de juego
    document.getElementById('screen-game').style.display = 'none';
    document.getElementById('screen-setup').style.display = 'block';
    showTab('play');
    showMessage('info', 'Partida abandonada. Los juegos registrados se han guardado.');
    
    // Recargar datos actualizados
    await loadHistory();
    await loadParejasStats();
    await renderRanking();
    
    // Limpiar variables
    currentPartidaId = null;
    active = {};
    scoresA = [];
    scoresB = [];
    handPlayerId = null;
}

// ============================================
// RENDERIZADO
// ============================================

function showTab(t) {
    if ((t === 'edit' || t === 'sett') && usuarioActual && usuarioActual.rol !== 'admin') {
        showMessage('error', 'No tienes permiso para acceder a esta sección');
        return;
    }
    
    document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
    document.getElementById('view-'+t).style.display = 'block';
    document.querySelectorAll('.btn-tab').forEach(b => b.classList.toggle('active', b.getAttribute('onclick').includes(t)));
    
    if (t === 'stats') {
        renderStats();
        loadParejasStats();
    }
    if (t === 'hist') {
        renderHistory();
    }
    if (t === 'rank') {
        renderRanking();
    }
    if (t === 'perfil') {
        cargarPerfil();
    }
}

function createSelectors() {
    const n = +document.getElementById('round-type').value;
    const numSelectores = n === 7 ? 6 : n;
    const area = document.getElementById('selector-grid'); 
    area.innerHTML = '';
    
    let ordenSelectores = [];
    let etiquetas = [];
    
    if (n === 7) {
        ordenSelectores = [0, 3, 2, 4, 1, 5];
        etiquetas = ['J1', 'J4', 'J3', 'J5', 'J2', 'J6'];
    } else if (n === 4) {
        ordenSelectores = [0, 1, 2, 3];
        etiquetas = ['J1', 'J2', 'J3', 'J4'];
    } else if (n === 5) {
        ordenSelectores = [0, 1, 2, 3, 4];
        etiquetas = ['J1', 'J2', 'J3', 'J4', 'J5'];
    } else if (n === 6) {
        ordenSelectores = [0, 1, 2, 3, 4, 5];
        etiquetas = ['J1', 'J2', 'J3', 'J4', 'J5', 'J6'];
    } else {
        ordenSelectores = Array.from({length: n}, (_, i) => i);
        etiquetas = Array.from({length: n}, (_, i) => `J${i+1}`);
    }
    
    for (let idx = 0; idx < ordenSelectores.length; idx++) {
        const posicionReal = ordenSelectores[idx];
        const etiqueta = etiquetas[idx];
        
        area.innerHTML += `
            <div class="select-wrapper" data-posicion="${posicionReal}">
                <img id="sel-avatar-${posicionReal}" class="select-avatar-preview" src="">
                <select id="sel-${posicionReal}" class="player-select" onchange="updateOptions()">
                    <option value="">-- ${etiqueta} --</option>
                </select>
            </div>`;
    }
    
    updateOptions();
}

function updateOptions() {
    const n = +document.getElementById('round-type').value;
    const numSelectores = n === 7 ? 6 : n;
    const selNames = Array.from({length:numSelectores}, (_,i) => {
        const sel = document.getElementById('sel-'+i);
        return sel ? sel.value : '';
    });
    
    for (let i = 0; i < numSelectores; i++) {
        const sel = document.getElementById('sel-'+i);
        if (!sel) continue;
        
        const av = document.getElementById('sel-avatar-'+i);
        const curr = selNames[i];
        const p = players.find(x=>x.name===curr);
        
        if (p) { 
            av.src = p.img; 
            av.style.display = 'block'; 
        } else { 
            av.style.display = 'none'; 
        }
        
        let opts = `<option value="">-- ${sel.placeholder || `Jugador ${i+1}`} --</option>`;
        players.forEach(p => {
            if (!selNames.some((v,idx) => v===p.name && idx!==i))
                opts += `<option value="${p.name}" ${curr===p.name?'selected':''}>${p.name}</option>`;
        });
        sel.innerHTML = opts;
    }
}

function renderPlayerList() {
    const container = document.getElementById('player-list');
    
    if (!players || players.length === 0) {
        container.innerHTML = '<div class="empty-state">No hay jugadores registrados</div>';
        return;
    }
    
    if (!esAdmin) {
        container.innerHTML = players.map(p => `
            <div class="player-item" style="cursor: default;">
                <img src="${p.img}" class="player-avatar" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${p.id}'">
                <div class="player-info">
                    <div class="player-name">${escapeHtml(p.name)}</div>
                    ${p.email ? `<div class="player-email">${escapeHtml(p.email)}</div>` : ''}
                </div>
            </div>
        `).join('');
    } else {
        container.innerHTML = players.map(p => `
            <div class="player-item" onclick="openEditor(${p.id})">
                <img src="${p.img}" class="player-avatar" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${p.id}'">
                <div class="player-info">
                    <div class="player-name">${escapeHtml(p.name)}</div>
                    ${p.email ? `<div class="player-email">${escapeHtml(p.email)}</div>` : ''}
                </div>
            </div>
        `).join('');
    }
}

async function renderRanking() {
    if (!grupoActual) return;
    try {
        const res = await apiRequest(`get_ranking.php?grupo_id=${grupoActual.id}`);
        if (!res.success) return;
        document.getElementById('rank-body').innerHTML = res.ranking.map((j,i) => `
            <div class="rank-row">
                <span style="width:25px;color:var(--gray);">${i+1}.</span>
                <img src="${j.img}" class="rank-avatar" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${j.name}'">
                <span class="rank-name">${escapeHtml(j.name)}</span>
                <span class="rank-rayas ${j.rayas_totales>=0?'txt-pos':'txt-neg'}">${j.rayas_totales>=0?'+':''}${j.rayas_totales}R</span>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error ranking:', error);
    }
}

async function renderStats() {
    if (!grupoActual) return;
    try {
        const res = await apiRequest(`get_stats.php?grupo_id=${grupoActual.id}`);
        if (!res.success) return;
        const stats = res.stats.sort((a,b)=>b.total_rayas-a.total_rayas);
        if (myChart) myChart.destroy();
        myChart = new Chart(document.getElementById('chartRayas'), {
            type: 'bar',
            data: {
                labels: stats.map(s=>s.name),
                datasets: [{
                    label: 'Rayas',
                    data: stats.map(s=>s.total_rayas),
                    backgroundColor: stats.map(s=>s.total_rayas>=0?'#30d158':'#ff453a')
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#333' }, ticks: { color: '#fff' } },
                    x: { ticks: { color: '#fff', maxRotation: 45, minRotation: 45 } }
                }
            }
        });
        document.getElementById('stats-summary').innerHTML = stats.map(s => `
            <div class="rank-row" style="padding: 5px 8px;">
                <div style="width: 28px; display: flex; justify-content: center;">
                    <img src="${s.img}" style="width: 26px; height: 26px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${s.name}'">
                </div>
                <div style="flex: 1;">
                    <div><strong style="font-size:11px;">${escapeHtml(s.name)}</strong></div>
                    <div style="font-size: 9px; color: var(--gray);">${s.total_juegos} juegos</div>
                </div>
                <div class="${s.total_rayas>=0?'txt-pos':'txt-neg'}" style="font-weight: bold; min-width: 50px; text-align: right; font-size:11px;">
                    ${s.total_rayas>=0?'+':''}${s.total_rayas}R
                </div>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error stats:', error);
    }
}

// ============================================
// HISTORIAL AGRUPADO POR MES
// ============================================

function renderHistory() {
    if (!db || !db.length) {
        document.getElementById('history-list').innerHTML = '<div style="text-align:center; color:var(--gray); padding:12px;">No hay partidas registradas para este grupo</div>';
        return;
    }

    const groupedByDay = {};
    db.slice().reverse().forEach(r => {
        const fechaStr = r.fecha || '';
        const fecha = fechaStr.split(' ')[0] || 'Fecha desconocida';
        if (!groupedByDay[fecha]) groupedByDay[fecha] = [];
        groupedByDay[fecha].push(r);
    });

    const fechas = Object.keys(groupedByDay).sort((a, b) => new Date(b) - new Date(a));
    
    const meses = {};
    
    fechas.forEach(fecha => {
        const date = new Date(fecha);
        const mesKey = `${date.getFullYear()}-${date.getMonth() + 1}`;
        const mesNombre = date.toLocaleString('es', { month: 'long', year: 'numeric' });
        const mesCapitalizado = mesNombre.charAt(0).toUpperCase() + mesNombre.slice(1);
        
        if (!meses[mesKey]) {
            meses[mesKey] = {
                nombre: mesCapitalizado,
                dias: {},
                totalPartidas: 0,
                totalJuegos: 0
            };
        }
        
        const partidasDelDia = groupedByDay[fecha];
        const totalJuegosDia = partidasDelDia.reduce((sum, p) => sum + (p.log?.length || 0), 0);
        
        meses[mesKey].dias[fecha] = {
            partidas: partidasDelDia,
            totalJuegos: totalJuegosDia,
            totalPartidas: partidasDelDia.length
        };
        
        meses[mesKey].totalPartidas += partidasDelDia.length;
        meses[mesKey].totalJuegos += totalJuegosDia;
    });
    
    let html = '';
    
    Object.keys(meses).sort((a, b) => b.localeCompare(a)).forEach(mesKey => {
        const mes = meses[mesKey];
        const mesId = 'mes-' + mesKey.replace(/-/g, '');
        
        html += `
            <div class="date-group">
                <div class="date-header" onclick="toggleMes('${mesId}')">
                    <span>📅 ${mes.nombre} <span style="font-size:9px; color:var(--gray);">(${mes.totalPartidas} partida${mes.totalPartidas !== 1 ? 's' : ''} · ${mes.totalJuegos} juego${mes.totalJuegos !== 1 ? 's' : ''})</span></span>
                    <span id="${mesId}-icon">▶</span>
                </div>
                <div id="${mesId}" class="hist-content" style="display:none;">
        `;
        
        Object.keys(mes.dias).sort((a, b) => new Date(b) - new Date(a)).forEach(fecha => {
            const dia = mes.dias[fecha];
            const partidasDelDia = dia.partidas;
            const totalJuegosDia = dia.totalJuegos;
            const totalPartidasDia = dia.totalPartidas;
            
            const statsDia = {};
            
            partidasDelDia.forEach(partida => {
                partida.jugadores?.forEach(j => {
                    if (!statsDia[j.n]) {
                        statsDia[j.n] = {
                            nombre: j.n,
                            id: j.id,
                            img: j.img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${j.n}`,
                            rayas: 0,
                            juegos: 0,
                            victorias: 0,
                            derrotas: 0,
                            manos: 0
                        };
                    }
                });
                
                partida.log?.forEach(juego => {
                    juego.gan?.forEach(g => {
                        const nombre = g.nombre || g;
                        if (statsDia[nombre]) {
                            statsDia[nombre].rayas += juego.r || 0;
                            statsDia[nombre].juegos++;
                            statsDia[nombre].victorias++;
                        }
                    });
                    
                    juego.per?.forEach(p => {
                        const nombre = p.nombre || p;
                        if (statsDia[nombre]) {
                            statsDia[nombre].rayas -= juego.r || 0;
                            statsDia[nombre].juegos++;
                            statsDia[nombre].derrotas++;
                        }
                    });
                    
                    if (juego.mano && statsDia[juego.mano]) {
                        statsDia[juego.mano].manos++;
                    }
                });
            });
            
            const rankingDia = Object.values(statsDia).sort((a, b) => b.rayas - a.rayas);
            const fechaId = 'fecha-' + fecha.replace(/[^a-zA-Z0-9]/g, '');
            const diaId = mesId + '-' + fechaId;
            
            html += `
                <div class="date-group" style="margin-left: 6px;">
                    <div class="date-header" onclick="toggleFecha('${diaId}')" style="background: #2c2c2e80; padding: 5px 6px;">
                        <span>📅 ${fecha} <span style="font-size:9px; color:var(--gray);">(${totalPartidasDia} partida${totalPartidasDia !== 1 ? 's' : ''} · ${totalJuegosDia} juego${totalJuegosDia !== 1 ? 's' : ''})</span></span>
                        <span id="${diaId}-icon">▶</span>
                    </div>
                    <div id="${diaId}" class="hist-content" style="display:none; margin-left: 6px;">
                        
                        <div class="dia-resumen">
                            <div class="dia-resumen-header">
                                <span>🏆 Ranking del Día - ${fecha}</span>
                                <span>Rayas</span>
                            </div>
                            <div class="dia-ranking">
                                ${rankingDia.length > 0 ? rankingDia.map((j, idx) => `
                                    <div class="dia-ranking-row">
                                        <div class="dia-ranking-pos">${idx + 1}</div>
                                        <div class="dia-ranking-avatar">
                                            <img src="${j.img}" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=${j.nombre}'">
                                        </div>
                                        <div class="dia-ranking-info">
                                            <div class="dia-ranking-nombre">${escapeHtml(j.nombre)}</div>
                                            <div class="dia-ranking-stats">${j.juegos}J · ${j.victorias}V · ${j.derrotas}D</div>
                                        </div>
                                        <div class="dia-ranking-rayas ${j.rayas >= 0 ? 'txt-pos' : 'txt-neg'}">
                                            ${j.rayas >= 0 ? '+' : ''}${j.rayas}R
                                        </div>
                                    </div>
                                `).join('') : '<div class="dia-ranking-empty">No hay datos para mostrar</div>'}
                            </div>
                        </div>
            `;
            
            for (let i = 0; i < partidasDelDia.length; i++) {
                const r = partidasDelDia[i];
                
                // ============================================
                // ⭐ OBTENER EL TIPO DE RONDA CORRECTAMENTE
                // ============================================
                let tipoTexto = 'Ronda ';
                let tipoRonda = r.tipo;
                
                if (tipoRonda === 4) tipoTexto = 'Ronda de 4 (3 juegos)';
                else if (tipoRonda === 5) tipoTexto = 'Ronda de 5 (5 juegos)';
                else if (tipoRonda === 6) tipoTexto = 'Ronda de 6 Triangular';
                else if (tipoRonda === 7) tipoTexto = 'Ronda de 6 Completa (8 juegos)';
                else tipoTexto = `Ronda ${tipoRonda}`;
                
                // Obtener el número de ronda si existe
                let numRonda = r.log?.[0]?.numRonda || 1;
                let rondaTexto = '';
                
                // Para triangular de 6, mostrar la ronda específica
                if (tipoRonda === 6) {
                    // Calcular ronda basada en el número de juego
                    // Cada ronda tiene 3 juegos
                    const totalJuegos = r.log?.length || 0;
                    const rondaCalculada = Math.ceil(totalJuegos / 3);
                    rondaTexto = ` (Ronda ${rondaCalculada})`;
                }
                
                html += `
                    <div class="history-card" style="margin-top: 6px;">
                        <div class="hist-header" onclick="toggleRonda(this)">
                            <span><b>${tipoTexto}${rondaTexto}</b> <small style="color:var(--gray); font-size:8px;">${r.fecha || ''}</small></span>
                            <span class="ronda-icon">▼</span>
                        </div>
                        <div class="ronda-content" style="display:none;">
                `;
                
                if (r.log && r.log.length) {
                    for (let j = 0; j < r.log.length; j++) {
                        const l = r.log[j];
                        const getImg = n => players.find(p=>p.name===n)?.img || `https://api.dicebear.com/7.x/avataaars/svg?seed=${n}`;
                        const claseEspecial = l.especial ? 'especial' : '';
                        
                        html += `
                            <div class="hist-item">
                                <div class="hist-game-header">
                                    <span>🁠 Juego ${l.j || '?'}`;
                        if (l.especial) html += ` <span class="badge especial" style="background:var(--special); color:#fff; font-size:7px; padding:1px 3px; border-radius:6px; margin-left:3px;">⚠️ Especial</span>`;
                        html += `</span>`;
                        if (l.mano) html += `<span style="color:var(--gold); font-size:9px;">👑 ${l.mano}</span>`;
                        html += `
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                                    <div style="display:flex;gap:3px;flex-wrap:wrap;">`;
                        if (l.gan && l.gan.length) {
                            for (let k = 0; k < l.gan.length; k++) {
                                const g = l.gan[k];
                                const nombre = g.nombre || g;
                                html += `<div class="player-chip ganador ${claseEspecial}"><img src="${getImg(nombre)}"><span>${escapeHtml(nombre)}</span></div>`;
                            }
                        }
                        html += `
                                    </div>
                                    <span class="txt-pos" style="font-size:13px;">+${l.r || 0}R</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <div style="display:flex;gap:3px;flex-wrap:wrap;">`;
                        if (l.per && l.per.length) {
                            for (let k = 0; k < l.per.length; k++) {
                                const p = l.per[k];
                                const nombre = p.nombre || p;
                                html += `<div class="player-chip perdedor ${claseEspecial}"><img src="${getImg(nombre)}"><span>${escapeHtml(nombre)}</span></div>`;
                            }
                        }
                        html += `
                                    </div>
                                    <span class="txt-neg" style="font-size:13px;">-${l.r || 0}R</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;margin-top:3px;font-size:8px;color:var(--gray);">
                                    <span>🏆 ${l.ptsW || 0} pts</span>
                                    <span>🎯 ${l.ptsL || 0} pts</span>
                                </div>`;
                        
                        if (l.resting && l.resting.length) {
                            html += `
                                <div style="margin-top:3px;font-size:8px;color:var(--gray);background:#2c2c2e;padding:2px 5px;border-radius:5px;">
                                    ⏳ Descanso: ${l.resting.map(i => {
                                        if (typeof i === 'object') return i.n || i.nombre || '';
                                        return i;
                                    }).filter(n => n).join(' & ')}
                                </div>`;
                        }
                        
                        html += `
                            </div>`;
                    }
                } else {
                    html += '<div class="hist-item">No hay juegos en esta ronda</div>';
                }
                
                html += `
                        </div>
                    </div>`;
            }
            
            html += `</div></div>`;
        });
        
        html += `</div></div>`;
    });
    
    document.getElementById('history-list').innerHTML = html;
}


function toggleMes(mesId) {
    const mesDiv = document.getElementById(mesId);
    const icon = document.getElementById(mesId + '-icon');
    if (mesDiv) {
        if (mesDiv.style.display === 'none' || mesDiv.style.display === '') {
            mesDiv.style.display = 'block';
            if (icon) icon.innerHTML = '▼';
        } else {
            mesDiv.style.display = 'none';
            if (icon) icon.innerHTML = '▶';
        }
    }
}

function toggleFecha(fechaId) {
    const fechaDiv = document.getElementById(fechaId);
    const icon = document.getElementById(fechaId + '-icon');
    if (fechaDiv) {
        if (fechaDiv.style.display === 'none' || fechaDiv.style.display === '') {
            fechaDiv.style.display = 'block';
            if (icon) icon.innerHTML = '▼';
        } else {
            fechaDiv.style.display = 'none';
            if (icon) icon.innerHTML = '▶';
        }
    }
}

function toggleRonda(headerElement) {
    let content = headerElement.nextElementSibling;
    while (content && !content.classList.contains('ronda-content')) {
        content = content.nextElementSibling;
    }
    
    if (content) {
        const icon = headerElement.querySelector('.ronda-icon');
        if (content.style.display === 'none' || content.style.display === '') {
            content.style.display = 'block';
            if (icon) icon.innerHTML = '▲';
        } else {
            content.style.display = 'none';
            if (icon) icon.innerHTML = '▼';
        }
    }
}

// ============================================
// EDITOR DE JUGADORES Y CAMBIO DE CONTRASEÑA
// ============================================

function openEditor(id = null) {
    editingId = id;
    const p = id ? players.find(x => x.id == id) : { 
        name: '', 
        img: `https://api.dicebear.com/7.x/avataaars/svg?seed=${Date.now()}`, 
        email: '', 
        phone: '', 
        bday: '' 
    };
    
    document.getElementById('edit-name').value = p.name;
    document.getElementById('edit-email').value = p.email || '';
    document.getElementById('edit-phone').value = p.phone || '';
    document.getElementById('edit-bday').value = p.bday || '';
    document.getElementById('edit-img-preview').src = p.img;
    document.getElementById('btn-delete-player').style.display = id ? 'block' : 'none';
    
    const cambiarPasswordDiv = document.getElementById('cambiar-password-section');
    if (cambiarPasswordDiv) {
        if (id === jugadorActual?.id && esAdmin) {
            cambiarPasswordDiv.style.display = 'block';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
            document.getElementById('password-message').innerHTML = '';
        } else {
            cambiarPasswordDiv.style.display = 'none';
        }
    }
    
    const imageInfo = document.getElementById('image-info');
    const progressContainer = document.getElementById('upload-progress');
    if (imageInfo) {
        imageInfo.innerHTML = `<span class="image-size">0 KB</span> - Haz clic para seleccionar imagen (máx 5MB)`;
    }
    if (progressContainer) {
        progressContainer.style.display = 'none';
    }
    
    document.getElementById('editor-overlay').style.display = 'flex';
}

async function cambiarPassword() {
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    const messageDiv = document.getElementById('password-message');
    
    if (!newPassword) {
        messageDiv.innerHTML = '<span style="color: var(--danger);">❌ Ingresa una nueva contraseña</span>';
        return;
    }
    
    if (newPassword.length < 4) {
        messageDiv.innerHTML = '<span style="color: var(--danger);">❌ La contraseña debe tener al menos 4 caracteres</span>';
        return;
    }
    
    if (newPassword !== confirmPassword) {
        messageDiv.innerHTML = '<span style="color: var(--danger);">❌ Las contraseñas no coinciden</span>';
        return;
    }
    
    showLoading(true);
    try {
        const res = await apiRequest('cambiar_password.php', 'POST', {
            usuario_id: usuarioActual.id,
            nueva_password: newPassword
        });
        
        if (res.success) {
            messageDiv.innerHTML = '<span style="color: var(--success);">✅ Contraseña cambiada correctamente</span>';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
            setTimeout(() => {
                messageDiv.innerHTML = '';
            }, 3000);
        } else {
            messageDiv.innerHTML = `<span style="color: var(--danger);">❌ ${res.error || 'Error al cambiar contraseña'}</span>`;
        }
    } catch (error) {
        messageDiv.innerHTML = '<span style="color: var(--danger);">❌ Error de conexión</span>';
    } finally {
        showLoading(false);
    }
}

async function savePlayer() {
    const name = document.getElementById('edit-name').value.trim();
    if (!name) { showMessage('error', 'Nombre requerido'); return; }
    
    const data = {
        name,
        email: document.getElementById('edit-email').value,
        phone: document.getElementById('edit-phone').value,
        bday: document.getElementById('edit-bday').value,
        img: document.getElementById('edit-img-preview').src
    };

    if (data.img.startsWith('data:')) {
        const base64Length = data.img.length - data.img.indexOf(',') - 1;
        const fileSize = Math.ceil(base64Length * 0.75);
        if (fileSize > MAX_IMAGE_SIZE) {
            showMessage('error', `La imagen es demasiado grande (${formatFileSize(fileSize)}). Máximo 5MB`);
            return;
        }
    }

    try {
        showLoading(true);
        const result = await apiRequest('save_player.php', 'POST', { id: editingId, ...data });
        if (result.success) {
            closeEditor();
            await loadPlayers();
            showMessage('success', 'Jugador guardado');
            if (document.getElementById('view-stats').style.display !== 'none') {
                loadParejasStats();
            }
        } else {
            throw new Error(result.error || 'Error al guardar');
        }
    } catch (error) {
        showMessage('error', error.message);
    } finally {
        showLoading(false);
    }
}

async function deletePlayer() { 
    if (!confirm('¿Eliminar este jugador permanentemente?')) return;
    try {
        showLoading(true);
        const result = await apiRequest('delete_player.php', 'POST', { id: editingId });
        if (result.success) {
            closeEditor();
            await loadPlayers();
            showMessage('success', 'Jugador eliminado');
            if (document.getElementById('view-stats').style.display !== 'none') {
                loadParejasStats();
            }
        } else {
            throw new Error(result.error || 'Error al eliminar');
        }
    } catch (error) {
        showMessage('error', error.message);
    } finally {
        showLoading(false);
    }
}

function closeEditor() { 
    document.getElementById('editor-overlay').style.display = 'none';
    editingId = null;
    document.getElementById('new-password').value = '';
    document.getElementById('confirm-password').value = '';
    document.getElementById('password-message').innerHTML = '';
}

document.getElementById('file-input').onchange = function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    if (file.size > MAX_IMAGE_SIZE) {
        showMessage('error', `La imagen es demasiado grande (${formatFileSize(file.size)}). Máximo 5MB`);
        this.value = '';
        return;
    }
    
    if (!file.type.match('image.*')) {
        showMessage('error', 'Solo se permiten imágenes');
        this.value = '';
        return;
    }
    
    const imageInfo = document.getElementById('image-info');
    imageInfo.innerHTML = `<span class="image-size">${formatFileSize(file.size)}</span> - ${file.name}`;
    updateProgress(0);
    
    const reader = new FileReader();
    reader.onloadstart = function() { updateProgress(10); };
    reader.onprogress = function(e) {
        if (e.lengthComputable) {
            const percent = (e.loaded / e.total) * 100;
            updateProgress(10 + percent * 0.8);
        }
    };
    reader.onload = function(ev) {
        updateProgress(100);
        document.getElementById('edit-img-preview').src = ev.target.result;
        setTimeout(() => { document.getElementById('upload-progress').style.display = 'none'; }, 500);
    };
    reader.onerror = function() {
        showMessage('error', 'Error al leer el archivo');
        document.getElementById('upload-progress').style.display = 'none';
    };
    reader.readAsDataURL(file);
};

// ============================================
// EXPORTACIÓN CSV
// ============================================

async function exportRankingCSV() {
    if (!grupoActual) {
        showMessage('error', 'No hay grupo activo');
        return;
    }
    try {
        const res = await apiRequest(`get_ranking.php?grupo_id=${grupoActual.id}`);
        if (!res.success) return;
        let csv = 'Posición,Jugador,Rayas,Juegos,Grupo\n';
        csv += `"${grupoActual.nombre}",,,,\n`;
        res.ranking.forEach((j,i) => csv += `${i+1},${j.name},${j.rayas_totales},${j.juegos_jugados},\n`);
        const blob = new Blob(['\uFEFF'+csv], {type:'text/csv'});
        const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `ranking_${grupoActual.nombre}_${Date.now()}.csv` });
        a.click();
        showMessage('success', `Ranking de ${grupoActual.nombre} exportado`);
    } catch (error) {
        showMessage('error', 'Error exportando ranking');
    }
}

async function exportHistoryCSV() {
    if (!grupoActual) {
        showMessage('error', 'No hay grupo activo');
        return;
    }
    try {
        const res = await apiRequest(`get_history.php?grupo_id=${grupoActual.id}`);
        if (!res.success) return;
        let csv = 'Fecha,Tipo,Juego,Ganadores,Perdedores,Rayas,PtsGanador,PtsPerdedor,Mano,Especial,Grupo\n';
        csv += `"${grupoActual.nombre}",,,,,,,,,,\n`;
        res.history.forEach(p => p.log?.forEach(l => {
            const g = l.gan?.map?.(g=>g.nombre||g).join(' & ')||'';
            const pe = l.per?.map?.(p=>p.nombre||p).join(' & ')||'';
            csv += `"${p.fecha}",${p.tipo},${l.j},"${g}","${pe}",${l.r},${l.ptsW},${l.ptsL},"${l.mano||''}",${l.especial||0},"${grupoActual.nombre}"\n`;
        }));
        const blob = new Blob(['\uFEFF'+csv], {type:'text/csv'});
        const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `historial_${grupoActual.nombre}_${Date.now()}.csv` });
        a.click();
        showMessage('success', `Historial de ${grupoActual.nombre} exportado`);
    } catch (error) {
        showMessage('error', 'Error exportando historial');
    }
}

async function exportParejasCSV() {
    if (!grupoActual) {
        showMessage('error', 'No hay grupo activo');
        return;
    }
    try {
        const res = await apiRequest(`get_stats_parejas.php?grupo_id=${grupoActual.id}`);
        if (!res.success) return;
        let csv = 'Grupo,Jugador1,Jugador2,Juegos,Victorias,Derrotas,Porcentaje,Rayas_Ganadas,Rayas_Perdidas,Rayas_Totales,Diferencia\n';
        csv += `"${grupoActual.nombre}",,,,,,,,,,\n`;
        res.parejas.forEach(p => {
            const porcentaje = (p.victorias / p.veces_jugadas * 100).toFixed(1);
            csv += `"${grupoActual.nombre}","${p.jugador1_nombre}","${p.jugador2_nombre}",${p.veces_jugadas},${p.victorias},${p.derrotas},${porcentaje}%,${p.rayas_ganadas},${p.rayas_perdidas},${p.rayas_totales},${p.diferencia}\n`;
        });
        const blob = new Blob(['\uFEFF'+csv], {type:'text/csv'});
        const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `parejas_${grupoActual.nombre}_${Date.now()}.csv` });
        a.click();
        showMessage('success', `Estadísticas de parejas de ${grupoActual.nombre} exportadas`);
    } catch (error) {
        showMessage('error', 'Error exportando estadísticas de parejas');
    }
}

async function resetHistory() {
    if (!grupoActual) {
        showMessage('error', 'No hay grupo activo');
        return;
    }
    if (!confirm(`¿Borrar TODO el historial del grupo "${grupoActual.nombre}"? Los jugadores se mantendrán.`)) return;
    if (!confirm(`⚠️ CONFIRMACIÓN FINAL: ¿Estás ABSOLUTAMENTE seguro de borrar el historial del grupo "${grupoActual.nombre}"?`)) return;
    
    showLoading(true);
    try {
        const res = await apiRequest(`reset_history.php?grupo_id=${grupoActual.id}`, 'POST');
        if (res.success) {
            await loadHistory();
            showMessage('success', `Historial de ${grupoActual.nombre} borrado correctamente`);
            if (document.getElementById('view-stats').style.display !== 'none') {
                loadParejasStats();
            }
            if (document.getElementById('view-rank').style.display !== 'none') {
                renderRanking();
            }
        } else {
            showMessage('error', res.error || 'Error al borrar historial');
        }
    } catch (error) {
        showMessage('error', 'Error de conexión');
    } finally {
        showLoading(false);
    }
}

// ============================================
// INICIALIZACIÓN
// ============================================

async function init() {
    try {
        const res = await apiRequest('verificar_sesion.php');
        if (res.success && res.usuario) {
            showMessage('info', 'Bienvenido de nuevo');
            document.getElementById('screen-login').style.display = 'none';
            document.getElementById('screen-setup').style.display = 'block';
            await loadPlayers();
            await loadHistory();
            createSelectors();
        }
    } catch (error) {
        console.log('No hay sesión activa');
    }
    createSelectors();
}

init();

// Registrar Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/multrigrupos/sw.js')
        .then(registration => {
            console.log('Service Worker registrado con éxito:', registration.scope);
        })
        .catch(error => {
            console.log('Error al registrar Service Worker:', error);
        });
}

// Función para finalizar el día
async function finalizarDia() {
    const confirmacion = confirm(
        '⚠️ FINALIZAR DÍA ⚠️\n\n' +
        'Esta acción:\n' +
        '• Cerrará TODAS las partidas activas del grupo\n' +
        '• Guardará los juegos registrados hasta el momento\n' +
        '• Enviará correos con los rankings\n' +
        '• No se podrá deshacer\n\n' +
        '¿Estás seguro de que deseas finalizar el día?'
    );
    
    if (!confirmacion) return;
    
    showLoading(true);
    
    try {
        const result = await apiRequest('finalizar_dia.php', 'POST');
        
        if (result.success) {
            let mensaje = `✅ Día finalizado correctamente\n\n`;
            mensaje += `📊 Resumen:\n`;
            mensaje += `• Partidas cerradas: ${result.partidas_cerradas}\n`;
            mensaje += `• Juegos registrados: ${result.juegos_registrados}\n`;
            mensaje += `• Correos enviados: ${result.correos_enviados}\n`;
            if (result.errores_envio && result.errores_envio.length > 0) {
                mensaje += `• Errores de envío: ${result.errores_envio.join(', ')}\n`;
            }
            mensaje += `• Hora: ${result.timestamp}`;
            
            alert(mensaje);
            showMessage('success', `Día finalizado: ${result.partidas_cerradas} partidas cerradas, ${result.correos_enviados} correos enviados`);
            
            await loadHistory();
            await loadParejasStats();
            await renderRanking();
        } else {
            showMessage('error', result.error || 'Error al finalizar el día');
        }
    } catch (error) {
        console.error('Error en finalizarDia:', error);
        showMessage('error', 'Error de conexión al finalizar el día');
    } finally {
        showLoading(false);
    }
}