/*
 * PUBLICACIONES -- la colección de la sección 3. Se muestran EN ESTE ORDEN:
 * la primera de la lista sale primero.
 * Para agregar una, copia un bloque { ... } y cambia sus datos:
 *   tipo:    'web' o 'facebook'
 *   titulo:  título de la publicación
 *   autor:   escritor o autor (opcional)
 *   fuente:  dónde se publicó (p. ej. "UDEP Hoy", "Facebook · Nombre de la página")
 *   fecha:   'AAAA-MM-DD' (opcional)
 *   resumen: dos o tres líneas
 *   enlace:  dirección de la publicación
 *   imagen:  'img/archivo.jpg' (opcional)
 * Para Facebook, el enlace es el de la publicación (botón "..." -> "Insertar" o
 * "Copiar enlace"): la página la muestra tal como se ve en Facebook. La
 * publicación tiene que ser PÚBLICA.
 */
window.PUBLICACIONES = [
  {
    tipo: 'facebook',
    titulo: 'El angelito',
    autor: 'David Arce',
    fuente: 'Facebook · David Arce (facebook.com/arcemartino)',
    fecha: '',
    resumen: '“Los que han venido después me han dicho que yo fui el primero. Han pasado hartísimos años desde entonces…”',
    enlace: 'https://www.facebook.com/1097015400/posts/10232821895505168'
  },
  {
    tipo: 'web',
    titulo: 'Tambogrande',
    autor: 'David Arce',
    fuente: 'Encuentro al Sur',
    fecha: '2011-04-09',
    resumen: 'Cuando descubren oro bajo el pueblo, una minera compra casas y tierras entre presiones y muertes sospechosas; solo la abuela del narrador se niega a vender, y los muertos le piden que cuente la historia.',
    enlace: 'https://encuentroalsur.com/?p=15093',
    imagen: 'https://i0.wp.com/encuentroalsur.com/wp-content/uploads/2011/04/tambogrande2.jpg'
  },
  {
    tipo: 'web',
    titulo: 'El nombre de Chulucanas',
    autor: 'Carlos Arrizabalaga',
    fuente: 'UDEP Hoy (Suplemento Semana, El Tiempo)',
    fecha: '2023-01-02',
    resumen: 'Repasa las explicaciones del nombre —aimara, quechua, los mitimaes de Canas y la anécdota popular— y concluye que su origen es incierto, quizá tallán.',
    enlace: 'https://www.udep.edu.pe/hoy/2023/01/el-nombre-de-chulucanas/'
  },
  {
    tipo: 'web',
    titulo: 'Héctor Manrique Carrasco, “El Vate”',
    autor: 'Municipalidad Provincial de Morropón-Chulucanas',
    fuente: 'gob.pe',
    fecha: '',
    resumen: 'Homenaje a “El Vate”, nacido en Chulucanas el 12 de mayo de 1897.',
    enlace: 'https://www.gob.pe/institucion/munichulucanas/campa%C3%B1as/93941-hector-manrique-carrasco-el-vate'
  },
  {
    tipo: 'web',
    titulo: 'Reconocimiento a destacados escritores chulucanenses',
    autor: 'Municipalidad Provincial de Morropón-Chulucanas',
    fuente: 'gob.pe · Resolución de Alcaldía (2024)',
    fecha: '2024',
    resumen: 'Resolución de alcaldía que reconoce a escritores de Chulucanas por su aporte a la cultura de la provincia.',
    enlace: 'https://cdn.www.gob.pe/uploads/document/file/7250324/6201356-resolucion-de-alcaldia-d000042-2024-al.pdf'
  }
  // Ejemplo de Facebook (quita las barras // y completa):
  // ,{
  //   tipo: 'facebook',
  //   titulo: 'Título de la publicación',
  //   autor: 'Nombre del escritor',
  //   fuente: 'Facebook · Nombre de la página',
  //   fecha: '2026-11-02',
  //   resumen: 'De qué trata.',
  //   enlace: 'https://www.facebook.com/NOMBRE/posts/NUMERO'
  // }
];
