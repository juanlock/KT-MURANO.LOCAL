<template>
  <!-- IT Admin (2026-11-28): the menu has HTD-IT's whole structure; a screen not migrated yet opens here with its phase. -->
  <div class="page">
    <a-result status="info" :title="item.title" :sub-title="L(`Being migrated from HTD-IT: phase ${item.phase}.`, `En migración desde HTD-IT: fase ${item.phase}.`)">
      <template #extra>
        <p class="soon-what">{{ item.what }}</p>
        <router-link to="/itadmin/networks"><a-button type="primary">{{ L('Go to Networks IP', 'Ir a Redes IP') }}</a-button></router-link>
      </template>
    </a-result>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useTr } from '../../lib/useTr';
import { useBarTitle } from '../../composables/useBarTitle';

const L = useTr();
const route = useRoute();
const ITEMS = {
  dashboard: [4, 'IT Dashboard', 'IT Dashboard', 'A command board: devices per location, internet lines, renewals of the next 12 months, health per location.', 'Tablero de mando: equipos por ubicación, líneas de internet, vencimientos de los próximos 12 meses, salud por ubicación.'],
  'knowledge-base': [4, 'Knowledge Base', 'Base de conocimiento', 'How-to articles with steps, files and secrets.', 'Artículos de cómo hacer, con pasos, archivos y secretos.'],
  applications: [4, 'Applications', 'Aplicaciones', 'The software the company uses: who uses it, its provider, links and contacts.', 'El software que usa la compañía: quién lo usa, su proveedor, enlaces y contactos.'],
  'network-map': [2, 'Networks', 'Redes', 'Each location\'s network diagram: ISP, firewall, networks and devices.', 'El diagrama de red de cada ubicación: ISP, firewall, redes y equipos.'],
  isp: [2, 'ISP Connections', 'Conexiones ISP', 'Internet lines: provider, account, speeds, public IPs, router and firewall.', 'Líneas de internet: proveedor, cuenta, velocidades, IPs públicas, router y firewall.'],
  vpn: [2, 'VPN tunnels', 'Túneles VPN', 'Site-to-site tunnels and client VPN users.', 'Túneles entre sedes y usuarios de VPN.'],
  'telephony-services': [2, 'Telephony services', 'Servicios de telefonía', 'Telephone lines and services with their provider and contract.', 'Líneas y servicios telefónicos con su proveedor y contrato.'],
  'home-networks': [2, 'Home Networks', 'Redes de casa', 'The home networks of people working from home.', 'Las redes de casa de quienes trabajan desde casa.'],
  'systems-services': [2, 'Network & Systems services', 'Servicios de red y sistemas', 'Hosting, domains, cloud and support services.', 'Hosting, dominios, nube y servicios de soporte.'],
  rooms: [2, 'Rooms', 'Salas', 'The rooms of each location and what is in them.', 'Las salas de cada ubicación y lo que hay en ellas.'],
  gallery: [2, 'Gallery', 'Galería', 'Photos of each location: racks, cabling, rooms.', 'Fotos de cada ubicación: racks, cableado, salas.'],
  'building-services': [2, 'Services / Providers', 'Servicios / Proveedores', 'Building services: power, alarm, cleaning, maintenance.', 'Servicios del local: energía, alarma, limpieza, mantenimiento.'],
  'it-services': [2, 'IT Services (all)', 'Servicios IT (todos)', 'Every IT service of every area.', 'Todos los servicios IT de todas las áreas.'],
  purchases: [3, 'Purchases', 'Compras', 'Equipment and supplies bought, linked to the devices.', 'Equipos y suministros comprados, ligados a los equipos.'],
  providers: [3, 'Providers', 'Proveedores', 'IT providers with their branches and contacts.', 'Proveedores de IT con sus sucursales y contactos.'],
  licenses: [3, 'Licenses', 'Licencias', 'Software licenses, keys, seats, renewals and who has them.', 'Licencias de software, claves, puestos, renovaciones y quién las tiene.'],
  passwords: [3, 'Passwords', 'Contraseñas', 'The encrypted vault of credentials, with access log and history.', 'La bóveda cifrada de credenciales, con registro de accesos e historial.'],
};
const item = computed(() => {
  const i = ITEMS[route.params.key] || [0, route.params.key, route.params.key, '', ''];
  return { phase: i[0], title: L(i[1], i[2]), what: L(i[3], i[4]) };
});
useBarTitle(() => item.value.title);
</script>

<style scoped>
.soon-what { color: #4b5563; max-width: 520px; margin: 0 auto 12px; }
</style>
