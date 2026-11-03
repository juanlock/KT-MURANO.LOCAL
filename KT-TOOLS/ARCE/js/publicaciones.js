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
 *   enlace:  dirección ORIGINAL de la publicación -- OBLIGATORIO: cada publicación
 *            muestra siempre su enlace de origen; sin enlace no se muestra.
 *   imagen:  'img/archivo.jpg' (opcional)
 *   insertar: (solo Facebook, opcional) la dirección corta que Facebook acepta para
 *            mostrar la publicación dentro de la página, cuando el enlace original
 *            es largo: https://www.facebook.com/PAGINA/posts/NUMERO
 *   video:   true cuando es un video de Facebook (se reproduce dentro de la página).
 * Para Facebook, la página muestra la publicación tal como se ve en Facebook.
 * La publicación tiene que ser PÚBLICA.
 */
window.PUBLICACIONES = [
  {
    tipo: 'facebook',
    titulo: 'Manuel David Arce Martino: biografía',
    autor: 'Jueves de Poesía y Narrativa',
    fuente: 'Facebook · Jueves de Poesía y Narrativa',
    fecha: '',
    resumen: 'Nacido en Chulucanas (1963), médico psiquiatra de niños y adolescentes en el Hospital Almenara, con estudios de posgrado en Lengua y Literatura y en Creación Literaria; autor de “Cuentos para Eva”, “Sopa de piedra” y “La casa de los cachorros”.',
    enlace: 'https://www.facebook.com/100053946682217/posts/manuel-david-arce-martino-1963-natural-de-chulucanas-piura-estudi%C3%B3-primaria-y-se/227431255643984/',
    insertar: 'https://www.facebook.com/100053946682217/posts/227431255643984'
  },
  {
    tipo: 'facebook',
    titulo: 'El angelito',
    autor: 'David Arce',
    fuente: 'Facebook · David Arce (facebook.com/arcemartino)',
    fecha: '',
    resumen: '“Los que han venido después me han dicho que yo fui el primero. Han pasado hartísimos años desde entonces…”',
    enlace: 'https://www.facebook.com/story.php?story_fbid=10232821895505168&id=1097015400',
    insertar: 'https://www.facebook.com/1097015400/posts/10232821895505168'
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
    titulo: 'El guardián de los retratos',
    autor: 'Manuel David Arce Martino',
    fuente: 'Encuentro al Sur',
    fecha: '2009-10-02',
    resumen: 'Un hombre sin trabajo acepta ser guardián nocturno de un viejo edificio junto a una iglesia, donde debe ordenar unos retratos; al final descubre que el último es el suyo, de ochenta años atrás.',
    enlace: 'https://encuentroalsur.com/?p=5791',
    imagen: 'https://i0.wp.com/encuentroalsur.com/wp-content/uploads/2009/10/espiritus.jpg'
  },
  {
    tipo: 'facebook',
    titulo: 'David Arce Martino, en la antología “El microrrelato peruano”',
    autor: 'Chulucanas Noticias',
    fuente: 'Facebook · Chulucanas Noticias',
    fecha: '',
    resumen: 'El escritor y médico psiquiatra chulucanense fue incluido en “El microrrelato peruano. Antología general”, de Ricardo González Vigil (páginas 759 a 767).',
    enlace: 'https://www.facebook.com/chulucanasnoticias/posts/el-escritor-y-m%C3%A9dico-psiquiatra-el-chulucanense-dr-david-arce-martino-fue-inclui/4963332487032778/',
    insertar: 'https://www.facebook.com/chulucanasnoticias/posts/4963332487032778'
  },
  {
    tipo: 'facebook',
    video: true,
    titulo: 'Cómo tratar la fobia escolar',
    autor: 'Dr. David Arce Martino',
    fuente: 'Facebook · Chulucanas Noticias (video)',
    fecha: '',
    resumen: 'La escuela puede causar miedo y ansiedad en algunos niños: el Dr. David Arce, médico psiquiatra especialista en niños y adolescentes, da consejos para el primer día de clases.',
    enlace: 'https://www.facebook.com/watch/?v=626848174550043',
    insertar: 'https://www.facebook.com/chulucanasnoticias/videos/626848174550043/'
  },
  {
    tipo: 'web',
    titulo: 'Niños y adolescentes muy distraídos, impulsivos e hiperactivos podrían tener TDAH',
    autor: 'EsSalud · con el Dr. David Arce Martino',
    fuente: 'gob.pe · EsSalud (nota de prensa)',
    fecha: '2026-03-14',
    resumen: 'El psiquiatra del Servicio de Psiquiatría de Niños y Adolescentes del Hospital Almenara explica cómo distinguir el TDAH de una distracción común, sus riesgos si no se trata y por qué el tratamiento debe empezar en la niñez.',
    enlace: 'https://www.gob.pe/institucion/essalud/noticias/1365978-essalud-ninos-y-adolescentes-muy-distraidos-impulsivos-e-hiperactivos-podrian-tener-trastorno-por-deficit-de-atencion-e-hiperactividad',
    imagen: 'https://cdn.www.gob.pe/uploads/document/file/9608172/standard_1365978-002.jpg'
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
