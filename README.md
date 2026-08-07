# SolarFatura

## Instalação em outra máquina (versão 1.0.0)

Esta primeira versão pública funciona localmente com XAMPP. Não é necessário conhecer programação, mas a instalação deve ser feita por alguém com permissão para instalar programas no Windows.

1. Baixe o código em [Releases](https://github.com/Farneyaurelio/SolarFatura/releases) e extraia o arquivo ZIP em uma pasta local, por exemplo `C:\SolarFatura`.
2. Instale o [XAMPP](https://www.apachefriends.org/pt_br/index.html), abra o **XAMPP Control Panel** e inicie apenas o serviço **Apache**.
3. Copie a pasta extraída para `C:\xampp\htdocs\SolarFatura`.
4. Instale o Poppler para Windows e deixe o executável `pdftotext.exe` disponível no `PATH` do Windows. Alternativamente, defina a variável de ambiente `SOLARFATURA_PDFTOTEXT` com o caminho completo do executável.
5. No arquivo `C:\xampp\php\php.ini`, confirme que a extensão `mbstring` está habilitada e reinicie o Apache se necessário.
6. Abra `http://localhost/SolarFatura/public/` no navegador.
7. No primeiro acesso, crie a conta de administrador. Depois, cadastre a gestora, a logo e os clientes antes de gerar faturas.

### Instalação automática do leitor de PDF

Para evitar a configuração manual do Poppler, abra o PowerShell na pasta do projeto e execute:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Instalar-Poppler.ps1
```

O script pede confirmação, baixa a última release do Poppler para Windows, instala os arquivos em uma pasta local do usuário e configura `SOLARFATURA_PDFTOTEXT`. Ao final, feche e abra o XAMPP novamente e reinicie o Apache.

### Dados e cópias de segurança

Os dados ficam exclusivamente em `storage/solarfatura.sqlite`; os PDFs enviados ficam em `storage/uploads`. Para fazer backup, pare o Apache e copie a pasta `storage` para um local seguro. Esses arquivos possuem dados pessoais e **não devem ser enviados ao GitHub**.

### Atualizações

No menu **Atualizações**, o sistema consulta as Releases deste repositório. Na versão atual, o download e a instalação ainda são manuais. A atualização automática será disponibilizada junto do instalador Windows, que fará cópia de segurança e verificará a integridade do pacote antes da troca de arquivos.

## Licença

O SolarFatura é software livre licenciado sob a [Licença MIT](LICENSE). Você pode usar, estudar, modificar e redistribuir o projeto, mantendo os avisos de licença.

Gerador local de faturas para energia por compensação: envie um PDF da Cemig, confira os dados extraídos, aplique o desconto sobre os kWh compensados e gere uma fatura pronta para imprimir ou salvar em PDF.

## Desenvolvimento local

- PHP 8.2 ou superior (incluído no XAMPP recente);
- Poppler para Windows, com o executável `pdftotext.exe` disponível no `PATH` do Windows. Neste computador, a instalação do Git já fornece esse executável e a aplicação o encontra automaticamente;
- Extensão PHP `mbstring` habilitada.

O Poppler é usado somente para extrair o texto nativo do PDF com o modo `-raw`. Os PDFs Cemig analisados possuem texto, portanto OCR não é necessário neste primeiro layout.

Se não quiser adicionar o Poppler ao `PATH`, defina a variável de ambiente `SOLARFATURA_PDFTOTEXT` com o caminho completo para `pdftotext.exe` antes de iniciar o Apache.

## Executar localmente

1. Copie esta pasta para `C:\xampp\htdocs\SolarFatura` ou crie um Virtual Host apontando para a pasta `public`.
2. Garanta que `pdftotext.exe` esteja no `PATH`. Para desenvolvimento, também é possível rodar `php -S localhost:8000 -t public` na raiz do projeto.
3. Acesse `http://localhost/SolarFatura/public/` (ou `http://localhost:8000`).
4. Envie uma conta Cemig, confirme os campos encontrados e selecione **Gerar fatura**.

Os PDFs enviados são guardados em `storage/uploads`, fora da pasta pública. Não exponha a aplicação diretamente na internet.

## Instalação para usuários finais

XAMPP é somente uma ferramenta de desenvolvimento. A distribuição para o usuário final terá instalador Windows, PHP e leitor de PDF incorporados, banco SQLite local e atualização com confirmação pelo GitHub. A arquitetura e os requisitos de segurança estão descritos em [DISTRIBUICAO_WINDOWS.md](DISTRIBUICAO_WINDOWS.md).

## Regra de cálculo inicial

```text
simulação sem fotovoltaica = consumo total × tarifa cheia + iluminação pública
valor da energia solar = kWh compensados × tarifa cheia × (1 - desconto/100)
total do cliente = valor da energia solar + disponibilidade + iluminação pública + acertos - bônus
economia = simulação sem fotovoltaica - total do cliente
```

O desconto incide exclusivamente na energia compensada. Todos os valores extraídos permanecem editáveis na conferência, e a memória de cálculo aparece na fatura.

## Clientes e unidades consumidoras

O titular impresso pela Cemig pode ser diferente do cliente comercial. Cadastre cada cliente no menu **Clientes e unidades consumidoras**, vinculando o nome de exibição ao número da unidade consumidora (UC). Ao importar uma fatura, o SolarFatura usa esse cadastro para mostrar o nome e endereço corretos na fatura gerada.

Cada cliente possui um perfil próprio com dados de contato, desconto comercial, histórico de faturas geradas, valor cobrado, economia, vencimento e status. Faturas novas entram como pendentes; a base já identifica vencidas para que, na próxima etapa, sejam incluídos alertas e registro de pagamento.

No histórico do cliente, cada cobrança pode ser aberta, editada ou excluída. Use **Marcar como paga** para registrar a quitação; o status muda para “Paga” e a cobrança deixa de compor os pendentes. As novas cobranças preservam uma prévia completa para reabertura posterior.

Na prévia da fatura, use **Salvar no histórico** para registrar a cobrança. A impressão sugere o arquivo no formato `SolarFatura - Cliente - MES-ANO.pdf`; os botões de e-mail e WhatsApp preparam a mensagem para o contato cadastrado. O envio do PDF como anexo continuará manual nesta etapa; uma integração automática exigirá credenciais de um provedor de e-mail e/ou API oficial do WhatsApp.

## Dados da fornecedora

Em **Empresa e e-mail**, preencha nome fantasia, razão social, CNPJ, endereço, contatos e chave Pix. Esses dados passam a identificar a fornecedora no cabeçalho da fatura/PDF. Nessa mesma tela, o assunto e o corpo do e-mail podem ser editados usando as variáveis listadas pelo sistema.

## Acesso local

No primeiro acesso, o sistema solicita a criação de uma conta de administrador. Em seguida, o login passa a ser obrigatório. As senhas são armazenadas apenas como hash no banco SQLite local; nunca em texto legível. O acesso é uma proteção local: se o aplicativo for disponibilizado na rede, a instalação deverá usar HTTPS e controles adicionais de usuários/permissões.
